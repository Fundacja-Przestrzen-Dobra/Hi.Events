# Fundacja Przestrzeń Dobra — deployment fork

This fork runs the event-registration instance for Małżeństwo.org. The Hi.Events application code is **unmodified
upstream code**, pinned to release **`v2.0.0`** (tagged in this repository). [`LICENCE`](LICENCE) applies unchanged.

## Branches

| Branch | Purpose |
| --- | --- |
| `develop` | upstream mirror — **never commit here** |
| `malzenstwo` (default) | our deployment files, rebased onto each new upstream release tag |
| tag `v2.0.0` | the exact source of the release we run |

## Running it

Production (app bound to `127.0.0.1:8080`, behind a TLS reverse proxy):

```bash
cp .env.example .env      # fill in real values; .env is never committed
docker compose up -d
```

Local environment (throwaway data, Mailpit for e-mail preview, reachable from the local network):

```bash
cp .env.example .env
docker compose -f docker-compose.local.yml up -d   # app on 0.0.0.0:8810, Mailpit UI on 8627
```

Generate the application keys once:

```bash
echo "APP_KEY=base64:$(openssl rand -base64 32)"
echo "JWT_SECRET=$(openssl rand -base64 32)"
```

## Licence and attribution

Hi.Events is licensed under **AGPL-3.0 with additional terms (§7b)**. This installation honours both obligations:

1. **Attribution** — the “Powered by Hi.Events” link stays in the footer of every page and every e-mail the software
   generates, legible and linked to `https://hi.events`. It may be rephrased but not hidden or removed without a
   commercial licence.
2. **Source offer (§13)** — the source of the version we run is the `v2.0.0` tag here (upstream code) together with our
   files on the `malzenstwo` branch. Anyone using this installation over the network can obtain it from this repository.

`backend/ee/` and `frontend/src/ee/` fall under a separate Enterprise licence; their features (reserved seating, box
office, company SSO) are not enabled here.

## Roadmap

A configurable payment-provider layer (first implementation: PayU) is planned — no other product plans are published in
this repository. See [`CHANGES.md`](CHANGES.md) for the delta against the upstream release.
