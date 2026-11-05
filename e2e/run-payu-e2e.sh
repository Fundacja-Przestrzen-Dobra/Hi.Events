#!/usr/bin/env bash
# Uruchamia test E2E przepływu PayU przeciw lokalnemu stosowi.
# Sekrety czytane z plików, nie z linii poleceń (nie trafiają do historii).
set -euo pipefail

cd "$(dirname "$0")"

export E2E_BASE_URL="${E2E_BASE_URL:-http://127.0.0.1:8810}"
export E2E_COMPOSE_FILE="${E2E_COMPOSE_FILE:-/home/michal/malzenstwo.org/docker-compose.stack.yml}"
export E2E_DB_SERVICE="${E2E_DB_SERVICE:-hievents-postgres}"
export E2E_DB_USER="${E2E_DB_USER:-malzenstwo_hievents}"
export E2E_DB_NAME="${E2E_DB_NAME:-hi_events}"
export E2E_ORGANIZER_ID="${E2E_ORGANIZER_ID:-2}"
export E2E_ADMIN_EMAIL="${E2E_ADMIN_EMAIL:-admin@malzenstwo.org}"

# hasło administratora i drugi klucz PayU z plików konfiguracyjnych
if [ -z "${E2E_ADMIN_PASSWORD:-}" ]; then
  E2E_ADMIN_PASSWORD="$(python3 - <<'PY'
import pathlib, re
text = pathlib.Path.home().joinpath(".hermes/cache/scratch/hi_fixes.py").read_text(encoding="utf-8")
print(re.search(r'"password":\s*"([^"]+)"', text).group(1))
PY
)"
  export E2E_ADMIN_PASSWORD
fi

if [ -z "${PAYU_SECOND_KEY:-}" ]; then
  PAYU_SECOND_KEY="$(grep '^PAYU_SECOND_KEY=' /home/michal/malzenstwo.org/.env.hievents | cut -d= -f2)"
  export PAYU_SECOND_KEY
fi

# lokalny bin, nie `npx` (npx potrafi ściągnąć samodzielny pakiet `playwright` bez konfiguracji)
exec ./node_modules/.bin/playwright test tests/checkout/payu-checkout.spec.ts --project=chromium --reporter=line "$@"
