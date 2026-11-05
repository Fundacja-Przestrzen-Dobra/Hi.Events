# Delta against upstream

Fork: `Fundacja-Przestrzen-Dobra/Hi.Events`
Upstream: `HiEventsDev/hi.events`
Baseline for comparison: **`v2.0.0`** (`5c7f34876f6d0c881a37a76f6caa583da5779d0e`, 2026-10-23)

## Rules

Only deployment files and our own modules go on `malzenstwo`. We avoid modifying application code: every change to an
upstream file must be listed here with its reason, so that bumping the pinned release stays cheap.

## Changes

### v1 — deployment files (2026-11-03)

Added:

- `docker-compose.yml` — production stack: application (`daveearley/hi.events-all-in-one:v2.0.0`), PostgreSQL 17,
  Redis 7, volumes for the database and uploaded files; app port bound to `127.0.0.1:8080` for a TLS reverse proxy.
- `docker-compose.local.yml` — local environment: same stack plus Mailpit (e-mail preview), app on `0.0.0.0:8810` so it
  can be tested from other devices on the local network.
- `.env.example` — every required variable with placeholder values (including reserved `PAYU_*` for the planned payment
  adapter).
- `DEPLOYMENT.md` — fork description, branch layout, how to run, licence and attribution obligations.
- `CHANGES.md` — this file.

Modified (upstream files):

- `README.md` — a four-line note inserted at the top pointing at `DEPLOYMENT.md` and `CHANGES.md`. Reason: the fork's
  landing page must state what this fork is and where the source of the running version is (AGPL §13).
- `.gitignore` — added `/.env` and `/.env.local`. Reason: upstream ignores `frontend/.env`, `backend/.env` and
  `docker/all-in-one/.env` but **not** the repository-root `.env`, which our compose files read; without this a
  `cp .env.example .env` followed by `git add -A` would commit live credentials.

No other changes to upstream files.

### Planned

- `v2 — payment provider adapter (PayU)`: `PaymentGatewayInterface` contract, provider registry, PayU module, the
  `order_payments` and `payment_webhook_events` migrations. Design: `malzenstwo.org/docs/04-architektura/payment-providers-adapter.md`.
  Target branch: `malzenstwo/payu`, merged here once proven.

## Bumping the upstream release

```bash
git fetch upstream --tags                      # upstream = https://github.com/HiEventsDev/hi.events.git
git checkout malzenstwo
git rebase <new-tag>                           # e.g. v2.1.0 — never rebase onto develop
```

`develop` is not a valid base: upstream cuts releases from separate branches and `v2.0.0` is not an ancestor of
`develop`. After a successful rebase: update the image tag in the compose files, add an entry here, push the source tag
to the fork, and smoke-test on the local stack.

## Licence

Everything in this repository is covered by [`LICENCE`](LICENCE) (AGPL-3.0 with additional terms, §7b), including our own
changes — they are offered under the same terms. The “Powered by Hi.Events” attribution stays visible on generated pages
and e-mails.
