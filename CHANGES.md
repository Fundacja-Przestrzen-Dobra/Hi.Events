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

## Planned

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
