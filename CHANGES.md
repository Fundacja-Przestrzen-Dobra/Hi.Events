# Delta against upstream

Fork: `Fundacja-Przestrzen-Dobra/Hi.Events`
Upstream: `HiEventsDev/hi.events`
Baseline: **`v2.0.0`** (`5c7f34876f6d0c881a37a76f6caa583da5779d0e`, 2026-10-23)

Only deployment files and our own modules live on `malzenstwo`. Application code stays untouched unless a change is
unavoidable; every change to an upstream file is listed here with its reason, so bumping the pinned release stays cheap.

## v1 — deployment files (2026-11-03)

Added:

- `docker-compose.yml` — production stack: application (`daveearley/hi.events-all-in-one:v2.0.0`), PostgreSQL 17,
  Redis 7, volumes for the database and uploaded files; app port bound to `127.0.0.1:8080` for a TLS reverse proxy.
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

## Planned

- Checkout wiring: generic intent/status/webhook routes, order transitions, frontend redirect and return page.
- Payment-provider layer behind an interface (first implementation: PayU).

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
