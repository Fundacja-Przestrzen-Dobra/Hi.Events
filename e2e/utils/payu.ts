import { createHash } from 'node:crypto';
import type { APIRequestContext } from '@playwright/test';
import { PAYU_SECOND_KEY } from './env';

/**
 * PayU notifications, signed the way the provider signs them.
 *
 * The provider's page cannot be automated, but its notification can: the signature is a
 * hash of the raw body with the POS second key, so a test delivers a genuine notification
 * and the backend treats it exactly as it treats the real one. This is what lets an E2E
 * test prove that a paid order is completed rather than merely recorded.
 */

export interface PayuNotification {
  /** The provider's order id, as returned by the intent endpoint. */
  providerReference: string;
  /** Our order id — the provider echoes it back as `extOrderId`. */
  orderShortId: string;
  status?: 'COMPLETED' | 'CANCELED' | 'PENDING';
  amountMinor?: number;
  currency?: string;
}

export const canDeliverPayuNotifications = (): boolean => PAYU_SECOND_KEY !== '';

export const PAYU_SKIP_REASON =
  'Requires PAYU_SECOND_KEY (the POS second key from the PayU panel) and a configured PayU provider.';

export async function deliverPayuNotification(
  request: APIRequestContext,
  notification: PayuNotification,
): Promise<void> {
  const body = JSON.stringify({
    order: {
      orderId: notification.providerReference,
      extOrderId: notification.orderShortId,
      status: notification.status ?? 'COMPLETED',
      totalAmount: String(notification.amountMinor ?? 0),
      currencyCode: notification.currency ?? 'PLN',
    },
  });

  const signature = createHash('md5').update(body + PAYU_SECOND_KEY).digest('hex');

  const response = await request.post('api/public/webhooks/payments/payu', {
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      'OpenPayu-Signature': `signature=${signature};algorithm=MD5;content=DOCUMENT`,
    },
    data: body,
  });

  if (!response.ok()) {
    throw new Error(`PayU notification → ${response.status()}: ${await response.text()}`);
  }
}
