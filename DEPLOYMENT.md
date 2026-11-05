# Deployment for Fundacja Przestrzeń Dobra (Małżeństwo.org)

This fork carries **deployment files and our own modules** for Małżeństwo.org event registrations. The Hi.Events
application code is unmodified and comes from release **`v2.0.0`** (`5c7f348`, 2026-10-23), tagged in this repository.
[`LICENCE`](LICENCE) applies unchanged.

- Instance: `zapisy.malzenstwo.org` (planned), run from this repository.
- Decision and design documents live in `Fundacja-Przestrzen-Dobra/malzenstwo.org`:
  [`docs/01-produkt/zapisy-hi-events.md`](https://github.com/Fundacja-Przestrzen-Dobra/malzenstwo.org/blob/main/docs/01-produkt/zapisy-hi-events.md)
  and
  [`docs/04-architektura/payment-providers-adapter.md`](https://github.com/Fundacja-Przestrzen-Dobra/malzenstwo.org/blob/main/docs/04-architektura/payment-providers-adapter.md).

## Branches

| Branch | Purpose |
| --- | --- |
| `develop` | upstream mirror — **never commit here**. Note: `develop` does **not** contain the `v2.0.0` release; upstream cuts releases from separate branches, so never rebase onto `develop`. |
| `malzenstwo` (default) | our deployment files and modules, rebased onto each new upstream release tag. |
| tag `v2.0.0` | the exact source of the release we run (AGPL §13 obligation). |

## Running it

Production (app bound to `127.0.0.1:8080`, behind the TLS reverse proxy):

```bash
cp .env.example .env      # fill in real values; .env is never committed
docker compose up -d
```

Local environment (throwaway data, Mailpit for e-mail preview, reachable from the local network):

```bash
cp .env.example .env
docker compose -f docker-compose.local.yml up -d   # app on 0.0.0.0:8810, Mailpit UI on 8627
```

Database migrations run automatically at container start (`php artisan migrate --force`). Backups need the `pgdata`
(database) and `app_storage` (uploaded files, e.g. event images) volumes.

Generate the two application keys once and keep them in the server `.env`:

```bash
echo "APP_KEY=base64:$(openssl rand -base64 32)"
echo "JWT_SECRET=$(openssl rand -base64 32)"
```

## Licence and attribution

Hi.Events is licensed under **AGPL-3.0 with additional terms (§7b)**. Our installation honours both obligations:

1. **Attribution** — the “Powered by Hi.Events” link stays in the footer of every page and every e-mail the software
   generates, legible and linked to `https://hi.events`. It may be rephrased (e.g. “Powered by Fundacja Przestrzeń Dobra
   based on Hi.Events”) but not hidden or removed without a commercial licence.
2. **Source offer (§13)** — the source of the version we run is the `v2.0.0` tag in this repository (upstream code)
   together with our files on the `malzenstwo` branch. Anyone using our installation over the network can obtain it here.

`backend/ee/` and `frontend/src/ee/` fall under a separate Enterprise licence — their features (reserved seating, box
office, company SSO) are not enabled here.

## Status and next steps

Working today: local environment with the full couple-registration flow, tickets, waitlist, offline payment and a
required terms/privacy consent question.

Not there yet:

- payment-provider adapter (direction: **PayU**) — planned on the `malzenstwo/payu` branch,
- production e-mail (Brevo) and the TLS subdomain,
- Przelewy24 will not be added: P24's terms do not cover a foundation (this also applies to Stripe's P24 method).

See [`CHANGES.md`](CHANGES.md) for the delta against the upstream release.
