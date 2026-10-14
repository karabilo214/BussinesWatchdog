#!/bin/sh
# Real payment attempts of a Shopware store (ADR 0056) against a real Shopware: a read-only Integration, orders through
# the Store API, payments failed and paid by state transitions; the backend reads them with the real connector and
# must see the attempts, open the «payments are not going through» incident after three failures and resolve it after
# a payment. Runs against its own database (BW_E2E_DATABASE, migrated fresh). Usage: attempts-e2e.sh [image ...]
set -eu
cd "$(dirname "$0")"
DOCKER_APP_BIN=/Applications/Docker.app/Contents/Resources/bin
[ -d "$DOCKER_APP_BIN" ] && PATH="$DOCKER_APP_BIN:$PATH"
BACKEND_DIR="$(cd ../../../../apps/backend && pwd)"
HELPER="$(pwd)/backend-e2e.php"
PHP="${PHP:-/opt/homebrew/opt/php@8.4/bin/php}"
DATABASE="${BW_E2E_DATABASE:-bw_attempts_e2e}"
ENV="DB_HOST=127.0.0.1 DB_DATABASE=${DATABASE} CACHE_STORE=database WATCHDOG_DEV_SHOPWARE_ENDPOINT=http://127.0.0.1:8480"
STATUS=0

backend() { (cd "$BACKEND_DIR" && env $ENV "$@" "$PHP" artisan tinker --execute="require '${HELPER}';" 2>/dev/null) | sed -n 's/^BW_E2E_JSON=//p'; }
shop() { python3 -I shop.py "$@" | sed -n 's/^BW_STORE=//p'; }
j() { python3 -c "import json,sys; print(json.loads(sys.argv[1])$2)" "$1"; }

(cd ../../../.. && docker compose up -d postgres >/dev/null && docker compose exec -T postgres sh -c "psql -U \"\$POSTGRES_USER\" -d postgres -tc \"SELECT 1 FROM pg_database WHERE datname = '${DATABASE}'\" | grep -q 1 || createdb -U \"\$POSTGRES_USER\" ${DATABASE}")
trap 'docker rm -f bw-shopware >/dev/null 2>&1 || true' EXIT

for IMAGE in ${*:-dockware/play:6.6.10.6 dockware/play:6.7.2.2}; do
    (cd "$BACKEND_DIR" && env $ENV "$PHP" artisan migrate:fresh --force >/dev/null)
    docker rm -f bw-shopware >/dev/null 2>&1 || true
    docker run -d --name bw-shopware -p 127.0.0.1:8480:80 "$IMAGE" >/dev/null
    ../wait-ready.sh
    echo "=== ${IMAGE}"
    keys=$(shop setup)
    setup=$(backend BW_E2E_ACTION=setup BW_E2E_BASE_URL="https://shopware-e2e.example.test" BW_E2E_ACCESS_KEY="$(j "$keys" "['access_key']")" BW_E2E_SECRET="$(j "$keys" "['secret']")")
    store=$(j "$setup" "['store_id']")
    sleep 3
    shop orders payment_prepayment 1 none >/dev/null
    shop orders payment_cashpayment 3 fail >/dev/null
    failing=$(backend BW_E2E_ACTION=sync BW_E2E_STORE_ID="$store")
    again=$(backend BW_E2E_ACTION=sync BW_E2E_STORE_ID="$store")
    shop orders payment_cashpayment 1 paid >/dev/null
    recovered=$(backend BW_E2E_ACTION=sync BW_E2E_STORE_ID="$store")
    echo "  connected: ${setup}"
    echo "  after failures: ${failing}"
    echo "  after a payment: ${recovered}"
    python3 - "$failing" "$again" "$recovered" <<'PY' || STATUS=1
import json, sys
failing, again, recovered = [json.loads(a) for a in sys.argv[1:]]
checks = {
    'orders read with the payment method handler included': failing['sync'] == 'ok' and recovered['sync'] == 'ok',
    'prepayment left open is a success (on hold)': 'payment_prepayment:on_hold' in failing['attempts'],
    'three failed payments are three failures': failing['attempts'].count('payment_cashpayment:failed:status_failed') == 3 and failing['windows']['failed'] == 3,
    'three failures open the incident': failing['incidents'] == ['CHECKOUT_PAYMENTS_FAILING:open'],
    'reading again changes nothing': again['attempts'] == failing['attempts'] and again['windows'] == failing['windows'] and again['incidents'] == failing['incidents'],
    'a payment resolves it': recovered['attempts'].count('payment_cashpayment:paid') == 1 and recovered['incidents'] == ['CHECKOUT_PAYMENTS_FAILING:resolved'],
    'coverage says observing': recovered['coverage'] == 'observing',
    'no personal data stored': not failing['pii'] and not recovered['pii'],
}
for name, ok in checks.items():
    print(('  PASS ' if ok else '  FAIL ') + name)
sys.exit(0 if all(checks.values()) else 1)
PY
done

echo "RESULT $([ $STATUS -eq 0 ] && echo PASS || echo FAIL)"
exit $STATUS
