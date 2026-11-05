# Delta against upstream

Fork: `Fundacja-Przestrzen-Dobra/Hi.Events`
Upstream: `HiEventsDev/hi.events`
Baseline: **`v2.0.0`** (`5c7f34876f6d0c881a37a76f6caa583da5779d0e`, 2026-10-23)

Only deployment files and our own modules live on `malzenstwo`. Application code stays untouched unless a change is
unavoidable; every change to an upstream file is listed here with its reason, so bumping the pinned release stays cheap.

## v1 — deployment files (2026-11-03)

Added:

- `docker-compose.yml` — production stack: application (built from this fork as `hi.events-malzenstwo:v2.0.0-payu`, see
  v6), PostgreSQL 17, Redis 7, volumes for the database and uploaded files; app port bound to `127.0.0.1:8080` for a TLS
  reverse proxy.
- `docker-compose.local.yml` — local stack: same plus Mailpit (e-mail preview), app on `0.0.0.0:8810`.
- `.env.example` — every required variable with placeholder values.
- `DEPLOYMENT.md` — fork description, branch layout, running it, licence and attribution obligations.
- `CHANGES.md` — this file.

Modified (upstream files):

- `README.md` — short note at the top pointing at `DEPLOYMENT.md` and `CHANGES.md`, so it is clear what this fork is and
  where the source of the running version is.
- `.gitignore` — added `/.env` and `/.env.local`. Upstream ignores `frontend/.env`, `backend/.env` and
  `docker/all-in-one/.env` but not the repository-root `.env`, which our compose files read; without this a
  `cp .env.example .env` followed by `git add -A` would commit live credentials.

## v2 — payment provider layer (branch `feature/payu`, in progress)

Added:

- `backend/app/Services/Domain/Payment/Gateway/` — `PaymentGatewayInterface` (redirect and embedded response shapes),
  DTOs (payment request/response, status, webhook event with an idempotency key, refund request/result), and
  `PaymentGatewayRegistry`, which resolves implementations from `config/malzenstwo.php`.
- `.../Gateway/Offline/OfflinePaymentGateway.php` — the existing offline flow expressed through the same contract.
- `.../Gateway/PayU/` — `PayuClient` (OAuth `client_credentials`, order creation with amounts in grosze, status, refunds),
  `PayuGateway` (contract implementation, PLN only) and `PayuWebhookSignatureValidator` (MD5/SHA-256 over the raw body
  with the POS second key).
- `backend/config/malzenstwo.php` — provider map plus the `payu` configuration block (`PAYU_*` env vars).
- `backend/database/migrations/2026_11_05_000001_create_order_payments_table.php` and
  `..._000002_create_payment_webhook_events_table.php` — provider-agnostic payment records and an idempotent webhook inbox.
- Unit tests: registry, offline gateway, PayU client status mapping and grosze conversion, webhook signature validation
  and notification parsing (15 tests). Full upstream Unit suite still passes (2228 tests).

Modified (upstream file):

- `backend/app/DomainObjects/Enums/PaymentProviders.php` — added `PAYU`.

Not yet done in this branch: checkout/routes wiring, applying the new migrations, the frontend redirect step, and the
fake gateway for CI without a provider.

## v3 — checkout wiring (branch `feature/payu`, in progress)

Added:

- `backend/app/Http/Actions/Orders/Payment/Gateway/` — `CreatePaymentIntentActionPublic` (resolves the order, builds the
  request server-side, never trusts a client amount), `GetPaymentStatusActionPublic` (checkout polling) and
  `PaymentWebhookActionPublic` (single endpoint for every provider: verify signature → store in the idempotent inbox →
  apply → mark processed; a duplicate delivery returns `duplicate` and changes nothing).
- `tests/Support/FakePaymentGateway.php` — in-memory gateway so the whole pipeline runs without a live provider.
- `tests/Feature/Payments/PaymentWebhookTest.php` — 5 feature tests against a real database: invalid signature rejected
  (401, nothing stored), valid notification stored and processed, duplicate delivery idempotent, unknown provider and
  offline webhooks rejected, status endpoint 404 for an unknown order.

Modified (upstream file):

- `backend/routes/api.php` — three public routes: `POST .../payments/{provider}/intent`,
  `GET .../payments/status`, `POST /webhooks/payments/{provider}`.

Note for tests: nginx rewrites `/api(/.*)` to `/index.php$1`, so application routes are registered without the `/api`
prefix — feature tests must call `/public/...`, not `/api/public/...`.

## v4 — frontend checkout (branch `feature/payu`)

Added:

- `frontend/src/api/order.client.ts` — `createProviderPaymentIntent` and `findProviderPaymentStatus` (provider-agnostic
  checkout calls against the new endpoints).
- `frontend/src/components/routes/product-widget/Payment/index.tsx` — `PAYU` as a payment method: auto-selected when
  enabled, tab in the selector when more than one provider is enabled, and submit → intent call → `window.location`
  redirect to PayU's hosted page. The method selector now appears whenever two or more providers are enabled (it used to
  require exactly Stripe+offline).
- `frontend/src/types.ts` — `PaymentProvider` union gains `PAYU`.
- `frontend/src/components/routes/event/Settings/Sections/PaymentSettings/index.tsx` — PayU added to the organizer's
  payment-method options, so a provider that the backend already supports can actually be enabled per event.

The return page needed no PayU branch: `PaymentReturn` already polls the order and navigates to the summary when
`order.status === COMPLETED`, which the webhook produces through the shared mark-as-paid transition.

Verification: `tsc --noEmit` clean across the frontend, and the production bundle builds — with the project's own
toolchain: **yarn with the committed `yarn.lock`** (`yarn install --frozen-lockfile && yarn build` under Node 22). Note
that `npm install` in this repository is a dead end (it ignores the lockfile, resolves `@swc/core` versions incompatible
with the pinned `@lingui/swc-plugin` wasm, and then fails on `entry.client.tsx`), and the vite client pass needs a raised
heap (`NODE_OPTIONS=--max-old-space-size=7168`, set in `Dockerfile.all-in-one`) — with Node's default it dies with
"Ineffective mark-compacts near heap limit".

## v5 — sandbox contract verification (branch `feature/payu`)

Verified against the real PayU sandbox (public test POS, `secure.snd.payu.com`): order creation returns an order id and
a hosted-page URL, `extOrderId` is our order short id, and status retrieval reports `PENDING` for a fresh order.

That run found a real bug, fixed here: PayU answers the redirect flow with **302 + `Location`** and Laravel's HTTP client
follows redirects by default, so the client received the hosted page instead of the URL. Order creation now disables
redirect following, reads the URL from the body or the `Location` header, and falls back to the `orderId` query
parameter when a bare 302 carries no body. Error messages now include the HTTP status and a truncated body.

Tests: 16 unit tests (a 302/`Location` regression test was added) and the 5 webhook feature tests remain green; the
upstream Unit suite is green at 2229 tests.

## v6 — settling a payment without a webhook, and an image built from this fork

Added:

- `GetPaymentStatusActionPublic` accepts `?verify=1`: for every outstanding payment it asks the provider for the current
  status and, when the provider reports the payment as settled, applies the same mark-as-paid transition the webhook uses.
  This is the fallback for a webhook that never arrived — and the only path that works locally, where a provider cannot
  reach an instance on a private network. A payment already applied is never applied twice, and a provider that cannot
  be reached never turns the checkout poll into an error.
- `frontend/src/queries/useGetOrderPaymentStatusPublic.ts`; `PaymentReturn` now uses it instead of the Stripe-specific
  intent fallback. After the short wait the page asks the backend to verify with the provider, and only reports a
  failure when the provider does not confirm either.

Modified (upstream files):

- `Dockerfile.all-in-one` — raises the Node heap for the frontend build (see v4).
- `.dockerignore` — excludes `frontend/node_modules`, `frontend/dist`, `backend/vendor`, `backend/node_modules` from the
  build context: the image installs its own dependencies, and shipping a developer's local tree slows the build down.
- `docker-compose.yml` / `docker-compose.local.yml` — the app service now **builds** `hi.events-malzenstwo:v2.0.0-payu`
  from this fork instead of pulling the upstream image. The upstream image does not contain our payment modules, so
  pulling it would silently run code without PayU.

## v7 — first live run: the checkout reaches PayU

Verified in a browser against the running stack: the payment step offers **PayU** and **Przelew** (Stripe is gone),
"Pay PLN 200.00" redirects to PayU's hosted sandbox page showing the right amount and buyer e-mail, and the order is
recorded in `order_payments` with status `PENDING`.

That run found two bugs the gateway unit tests could not see, both in the mapping from a stored order to a provider
request — order items are a domain-object collection (passing them to `array_map` threw a `TypeError`, HTTP 500) and the
line-item name was read through a non-existent `getName()` instead of `getItemName()`. The mapping now lives in
`PaymentRequestFactory` with its own unit tests.

Deployment note: the SPA calls the API at `VITE_API_URL_CLIENT`. Opening the app on a host that differs from the one in
that variable (for example `127.0.0.1` while the variable says the LAN address) makes every API call cross-origin and the
payment step renders "No payment methods are currently available" — the frontend never gets the event settings. Portal
links must therefore use the same host the instance is configured with.

## v8 — a paid online order is completed; instance language

A real sandbox payment exposed the gap: PayU confirmed 700 PLN, the payment row became `PAID`,
and the order stayed `RESERVED` — the buyer saw "we could not confirm your payment". The shared
mark-as-paid transition only accepted an order awaiting an offline transfer. It now accepts a
reserved order when the caller is an online provider (the provider's confirmation is the
authority): the order becomes `COMPLETED`, attendees `ACTIVE`, and the confirmation e-mail goes
out. Both the webhook and the verify-on-return path pass the flag.

Also in this change:

- `VITE_DEFAULT_LOCALE` — an instance default language that wins over the browser's
  Accept-Language but never over the visitor's own choice. Set to `pl` here, so the checkout is
  Polish for visitors whose browser is not.
- The dead Stripe tab is no longer rendered in the payment step (it showed up as a useless
  "Online" option next to PayU), and the PayU notice is Polish.
- Remaining seats: the registration system publishes the count when the organizer enables it
  (`show_quantity_remaining` / `show_available_occurrence_capacity`); `999999999` means
  "no limit". The portal's catalogue reads it, so cards show how many places are left.

## v9 — our branding, one section per event, and a readable weekend

- **Favicon and web-app icon** are ours (`frontend/public/manifest-icons/*`, rendered from the
  portal's mark), and `site.webmanifest` carries our name and accent colour. The licence
  (`LICENCE`, first 11 lines) protects one thing only — the visible "Powered by Hi.Events"
  attribution in the footer of pages and e-mails, linked to https://hi.events — and never
  mentions icons, the logo or colours. That attribution stays exactly where it was.
- **One section per event.** Each run of the example-events script used to create a fresh
  "Zapisy" category, so the event page grew a second, empty one next to an empty "Bilety";
  the catalogue became "Bilety / Brak dostępnych biletów / Zapisy / Brak dostępnych produktów".
  The script now reuses a category named "Zapisy" when one exists, and empty "Bilety" categories
  are hidden on our events.
- **A weekend no longer reads as one evening.** For a range that crosses midnight the end used
  to be printed as "15. lut 14:00" beside the start's full day, so "sobota, 13 lutego 2027 ·
  17:00 – 15. lut 14:00" was easy to misread. The end now carries its own day: "17:00 –
  poniedziałek, 15 lutego 2027, 14:00".
- **`pl` catalogue**: "Tickets" is "Zapisy" (the event page's own region label), so no screen
  mixes "bilety" with "zapisy". The remaining catalogue is still untouched upstream text.

## Planned

- Webhook delivery in staging/production: needs a publicly reachable `notifyUrl` (the VPS deployment provides it).
- Polish translations for the checkout screens: the new labels are Polish already, the rest of the upstream checkout is
  English; the locale catalogues are only patched where our wording differs (see v9).
- Bumping the pinned upstream release (see below).

## Bumping the upstream release

```bash
git fetch upstream --tags                      # upstream = https://github.com/HiEventsDev/hi.events.git
git checkout malzenstwo
git rebase <new-tag>                           # never rebase onto develop
```

`develop` is not a valid base: upstream cuts releases from separate branches. After a rebase: update the image tag in
the compose files, add an entry here, push the corresponding source tag to this fork, and smoke-test the local stack.

## Licence

Everything here is covered by [`LICENCE`](LICENCE) (AGPL-3.0 with additional terms, §7b), including our own changes.
The “Powered by Hi.Events” attribution stays visible on generated pages and e-mails.
