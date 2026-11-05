# Fundacja Przestrzeń Dobra — deployment fork

This fork runs the event-registration instance for Małżeństwo.org. It is a fork of Hi.Events release **`v2.0.0`**
(tagged in this repository) with a **payment-provider layer** added on top: PayU (redirect flow, cards/BLIK/bank
transfer, PLN) and the upstream direct-transfer (offline) flow, both behind one interface. Everything else is upstream
behaviour. [`LICENCE`](LICENCE) applies unchanged; [`CHANGES.md`](CHANGES.md) lists every change against upstream.

## Branches

| Branch | Purpose |
| --- | --- |
| `develop` | upstream mirror — **never commit here** |
| `malzenstwo` | the source we run: deployment files plus our payment modules |
| `feature/*` | work in progress on top of `malzenstwo` |
| tag `v2.0.0` | upstream release this fork is based on, exactly as published by upstream |

Bumping upstream means rebasing `malzenstwo` onto the next release tag — never onto `develop` (upstream cuts releases
from separate branches).

## Running it

The application image is **built from this repository**: the upstream image does not contain the payment modules, so
`docker compose up` alone would run code without them.

Production (app bound to `127.0.0.1:8080`, behind a TLS reverse proxy):

```bash
cp .env.example .env      # fill in real values; .env is never committed
docker compose up -d --build
```

Local environment (throwaway data, Mailpit for e-mail preview, reachable from the local network):

```bash
cp .env.example .env
docker compose -f docker-compose.local.yml up -d --build   # app on 0.0.0.0:8810, Mailpit UI on 8627
```

Generate the application keys once:

```bash
echo "APP_KEY=base64:$(openssl rand -base64 32)"
echo "JWT_SECRET=$(openssl rand -base64 32)"
```

Payments are configured per provider through environment variables — with `PAYU_POS_ID`, `PAYU_CLIENT_ID`,
`PAYU_CLIENT_SECRET`, `PAYU_SECOND_KEY` and `PAYU_SANDBOX`, or the deployment's own payment credentials. A provider
whose credentials are missing is simply not offered in the checkout. Payments are confirmed either by the provider's
notification (`POST /api/webhooks/payments/{provider}`) or, when the buyer returns without one, by asking the provider
for the payment status.

## Licence and attribution

Hi.Events is licensed under **AGPL-3.0 with additional terms (§7b)**. This installation honours both obligations:

1. **Attribution** — the “Powered by Hi.Events” link stays in the footer of every page and every e-mail the software
   generates, legible and linked to `https://hi.events`. It may be rephrased but not hidden or removed without a
   commercial licence.
2. **Source offer (§13)** — the source of the version we run is this repository: upstream release `v2.0.0` plus our
   files on the `malzenstwo` branch. Anyone using this installation over the network can obtain it from here.

`backend/ee/` and `frontend/src/ee/` fall under a separate Enterprise licence; their features (reserved seating, box
office, company SSO) are not enabled here.
