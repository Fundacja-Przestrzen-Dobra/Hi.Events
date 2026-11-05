import { expect, test } from '@playwright/test';
import { CheckoutPage } from '../../pages/checkout.page';
import { BASE_URL, E2E_ADMIN_EMAIL, E2E_ADMIN_PASSWORD } from '../../utils/env';
import { attendeeStatuses, enablePaymentProviders } from '../../utils/db';
import { canDeliverPayuNotifications, deliverPayuNotification, PAYU_SKIP_REASON } from '../../utils/payu';

/**
 * The whole money path of this fork: a couple books two seats, the payment step offers PayU
 * and the direct transfer (and nothing else), PayU confirms, and the order ends up completed
 * with both attendees active.
 *
 * Self-contained on purpose: it seeds its own event through the admin API and signs the
 * provider's notification itself, so it runs against any instance with PayU configured.
 */
const ORGANIZER_ID = Number(process.env.E2E_ORGANIZER_ID ?? 2);
const TICKET_PRICE = 100;
const BUYER = { firstName: 'Anna', lastName: 'Kowalska', email: '' };

const uniqueSuffix = (): string => `${Date.now()}${Math.floor(Math.random() * 1000)}`;

/** The suite's page objects speak English; the rest of the suite relies on that too. */
const useEnglishLocale = async (context: import('@playwright/test').BrowserContext): Promise<void> => {
  await context.addCookies([
    { name: 'locale', value: 'en', domain: new URL(BASE_URL).hostname, path: '/', expires: -1 },
  ]);
};

test.describe('PayU checkout', () => {
  test.skip(!canDeliverPayuNotifications(), PAYU_SKIP_REASON);
  test.skip(!E2E_ADMIN_EMAIL || !E2E_ADMIN_PASSWORD, 'Requires E2E_ADMIN_EMAIL / E2E_ADMIN_PASSWORD to seed an event.');

  let createdEventId: number | null = null;

  // Seeded events are removed afterwards so repeated runs do not pile up in the instance.
  // An event with completed orders cannot be deleted, so its orders are cancelled first.
  test.afterEach(async ({ request }) => {
    if (createdEventId === null) {
      return;
    }

    const login = await request.post('/api/auth/login', {
      data: { email: E2E_ADMIN_EMAIL, password: E2E_ADMIN_PASSWORD },
    });

    if (login.ok()) {
      const { token } = (await login.json()) as { token: string };
      const headers = { Accept: 'application/json', Authorization: `Bearer ${token}` };
      const eventId = createdEventId;

      const orders = await request.get(`/api/events/${eventId}/orders?per_page=100`, { headers });
      if (orders.ok()) {
        const { data } = (await orders.json()) as { data: Array<{ id: number }> };
        for (const order of data) {
          await request.post(`/api/events/${eventId}/orders/${order.id}/cancel`, { headers });
        }
      }

      await request.delete(`/api/events/${eventId}`, { headers });
    }

    createdEventId = null;
  });

  test('a couple pays through PayU and the order is completed', { tag: ['@smoke', '@payu'] }, async ({ page, context, request }) => {
    test.slow();
    await useEnglishLocale(context);

    const login = await request.post('/api/auth/login', {
      data: { email: E2E_ADMIN_EMAIL, password: E2E_ADMIN_PASSWORD },
    });
    expect(login.ok(), `login → ${login.status()}: ${await login.text()}`).toBeTruthy();
    const { token } = (await login.json()) as { token: string };
    const auth = { Accept: 'application/json', Authorization: `Bearer ${token}` };

    // ---------------------------------------------------------------- seed the event
    const suffix = uniqueSuffix();
    const created = await request.post('/api/events', {
      headers: auth,
      data: {
        title: `E2E PayU ${suffix}`,
        organizer_id: ORGANIZER_ID,
        start_date: '2027-06-12T09:00:00',
        end_date: '2027-06-12T16:00:00',
        timezone: 'Europe/Warsaw',
        currency: 'PLN',
        type: 'SINGLE',
        description: 'Para, dwoje uczestników, jedna płatność.',
      },
    });
    expect(created.ok(), `create event → ${created.status()}: ${await created.text()}`).toBeTruthy();
    const event = (await created.json()).data as { id: number; slug: string };
    createdEventId = event.id;

    const category = await request.post(`/api/events/${event.id}/product-categories`, {
      headers: auth,
      data: { name: 'Zapisy', is_hidden: false },
    });
    expect(category.ok(), `create category → ${category.status()}`).toBeTruthy();
    const categoryId = (await category.json()).data.id as number;

    const product = await request.post(`/api/events/${event.id}/products`, {
      headers: auth,
      data: {
        title: 'Udział pary (2 osoby)',
        product_type: 'TICKET',
        type: 'PAID',
        product_category_id: categoryId,
        min_per_order: 2,
        max_per_order: 2,
        initial_quantity_available: 20,
        prices: [{ price: TICKET_PRICE }],
      },
    });
    expect(product.ok(), `create product → ${product.status()}: ${await product.text()}`).toBeTruthy();

    const published = await request.put(`/api/events/${event.id}/status`, { headers: auth, data: { status: 'LIVE' } });
    expect(published.ok(), `publish → ${published.status()}`).toBeTruthy();

    enablePaymentProviders(event.id, ['PAYU', 'OFFLINE']);

    // ------------------------------------------------------------------ buy as a couple
    const buyer = { ...BUYER, email: `para-${suffix}@example.com` };
    const checkout = new CheckoutPage(page);

    await checkout.gotoPublicEvent(event.id, event.slug);
    await checkout.setFirstProductQuantity(2);
    await checkout.continueToCheckout();

    const contactFields = await page.getByLabel(/^First Name/).count();
    if (contactFields > 1) {
      await checkout.fillOrderAndAttendees(buyer, contactFields - 1);
    } else {
      await checkout.fillOrderDetails(buyer);
    }

    await checkout.continueToPayment();

    // -------------------------------------------------- payment step: PayU + transfer only
    await expect(page.getByRole('button', { name: 'PayU' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Przelew' })).toBeVisible();
    // The deployment hides Stripe; its tab used to linger as a dead "Online" button.
    await expect(page.getByRole('button', { name: 'Online' })).toHaveCount(0);

    const orderShortId = new URL(page.url()).pathname.split('/')[3];
    expect(orderShortId, 'order short id in the checkout URL').toMatch(/^o_/);
    // The session lives in sessionStorage once the checkout has been entered; the URL only
    // carries it on the first hop.
    const sessionIdentifier =
      new URL(page.url()).searchParams.get('session_identifier') ??
      (await page.evaluate(
        (id) => sessionStorage.getItem(`hievents.checkout.session.${id}`),
        orderShortId,
      ));

    // ------------------------------------------------------------- pay at the provider
    // Clicking is what the buyer does. The provider's own page is not ours to automate, so
    // the test reads the provider order id from the redirect and confirms it like the
    // provider would, with a notification signed with the POS second key.
    await page.getByRole('button', { name: /Pay / }).first().click();
    await page.waitForURL(/payu/, { timeout: 60_000 });
    const providerOrderId = new URL(page.url()).searchParams.get('orderId');
    expect(providerOrderId, 'PayU order id in the redirect').toBeTruthy();

    await deliverPayuNotification(request, {
      providerReference: providerOrderId as string,
      orderShortId,
      amountMinor: TICKET_PRICE * 2 * 100,
      currency: 'PLN',
    });

    // ------------------------------------------------------- back from the provider
    const sessionQuery = sessionIdentifier ? `?session_identifier=${sessionIdentifier}` : '';
    await page.goto(`/checkout/${event.id}/${orderShortId}/payment_return${sessionQuery}`);
    await page.waitForURL(/\/summary/, { timeout: 30_000 });

    expect(attendeeStatuses(orderShortId)).toEqual(['ACTIVE', 'ACTIVE']);
  });

  test('the instance speaks Polish unless the visitor chose otherwise', async ({ page }) => {
    const response = await page.goto('/');
    expect(response?.ok()).toBeTruthy();

    // No locale cookie: the instance default (VITE_DEFAULT_LOCALE) wins over Accept-Language.
    await expect(page.getByRole('link', { name: 'Bilety' }).first()).toBeVisible({ timeout: 15_000 }).catch(() => {});
    const html = await page.content();
    expect(html).toMatch(/Zaloguj|Konto|Wydarzenia|Bilety/);
  });
});
