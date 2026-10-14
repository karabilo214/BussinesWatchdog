#!/bin/sh
# Real payment attempts of a WooCommerce store connected only with a REST API read key (ADR 0060), against real
# WooCommerce without the Watchdog plugin: checkout orders created after the connection fail, one admin order fails, a
# bank transfer waits; the real connector must see three checkout failures of one method as the «payments are not
# going through» incident and a later paid checkout order must resolve it. Own database (BW_E2E_DATABASE, migrated
# fresh). Usage: rest-api-attempts-e2e.sh [target...]
set -eu
cd "$(dirname "$0")"
DOCKER_APP_BIN=/Applications/Docker.app/Contents/Resources/bin
[ -d "$DOCKER_APP_BIN" ] && PATH="$DOCKER_APP_BIN:$PATH"
BACKEND_DIR="$(cd ../../../../apps/backend && pwd)"
HELPER="$(pwd)/rest-api-attempts-backend.php"
PHP="${PHP:-/opt/homebrew/opt/php@8.4/bin/php}"
DATABASE="${BW_E2E_DATABASE:-bw_wc_attempts_e2e}"
STATUS=0

(cd ../../../.. && docker compose up -d postgres >/dev/null && docker compose exec -T postgres sh -c "psql -U \"\$POSTGRES_USER\" -d postgres -tc \"SELECT 1 FROM pg_database WHERE datname = '${DATABASE}'\" | grep -q 1 || createdb -U \"\$POSTGRES_USER\" ${DATABASE}")

for TARGET in ${*:-floor latest}; do
    BW_MATRIX_KEEP=1 BW_TEST_FILTER=install ./run.sh "$TARGET" >/dev/null 2>&1 || { echo "matrix setup failed"; exit 1; }
    DC="docker compose -p bwmatrix-${TARGET} -f docker-compose.yml"
    WP="$DC exec -T -u www-data wordpress php /bw-cache/wp-cli.phar --path=/var/www/html"
    $WP plugin deactivate business-watchdog >/dev/null
    $WP rewrite structure '/%postname%/' --hard >/dev/null 2>&1
    PORT=$($DC port wordpress 80 | cut -d: -f2)
    ENV="DB_HOST=127.0.0.1 DB_DATABASE=${DATABASE} CACHE_STORE=database WATCHDOG_DEV_WC_API_ENDPOINT=http://127.0.0.1:${PORT}"
    (cd "$BACKEND_DIR" && env $ENV "$PHP" artisan migrate:fresh --force >/dev/null)
    store() { $DC exec -T -u www-data -e BW_STEP="$1" wordpress php /bw-cache/wp-cli.phar --path=/var/www/html eval-file /var/www/html/wp-content/plugins/business-watchdog/tests/matrix/rest-api-attempts-store.php 2>/dev/null | sed -n 's/^BW_STORE=//p'; }
    backend() { (cd "$BACKEND_DIR" && env $ENV "$@" "$PHP" artisan tinker --execute="require '${HELPER}';" 2>/dev/null) | sed -n 's/^BW_E2E_JSON=//p'; }
    j() { python3 -c "import json,sys; print(json.loads(sys.argv[1])$2)" "$1"; }

    echo "=== ${TARGET} ($(grep "^${TARGET}=" targets.env | cut -d= -f2-)), Watchdog plugin not active"
    key=$(store key)
    setup=$(backend BW_E2E_ACTION=setup BW_E2E_BASE_URL="https://shop-${TARGET}.example.test" BW_E2E_READ_KEY="$(j "$key" "['key']")" BW_E2E_READ_SECRET="$(j "$key" "['secret']")")
    store_id=$(j "$setup" "['store_id']")
    sleep 2
    failures=$(store failures)
    failing=$(backend BW_E2E_ACTION=sync BW_E2E_STORE_ID="$store_id")
    again=$(backend BW_E2E_ACTION=sync BW_E2E_STORE_ID="$store_id")
    paid=$(store paid)
    sleep 1
    recovered=$(backend BW_E2E_ACTION=sync BW_E2E_STORE_ID="$store_id")
    echo "  shop: ${key%%\"secret*}… failures=${failures} paid=${paid}"
    echo "  after failures: ${failing}"
    echo "  after a payment: ${recovered}"
    python3 - "$key" "$failures" "$paid" "$failing" "$again" "$recovered" <<'PY' || STATUS=1
import json, sys
key, failures, paid, failing, again, recovered = [json.loads(a) for a in sys.argv[1:]]
a = failing['attempts']
failed = [str(i) for i in failures['failed']]
checks = {
    'connector read the orders with created_via': failing['sync'] == 'ok' and recovered['sync'] == 'ok',
    'the order before the connection is history': str(key['before']) not in a,
    'admin orders are not attempts': str(failures['admin']) not in a,
    'checkout failures (Classic and Blocks) are failures': [a.get(i) for i in failed] == ['stripe:failed:status_failed'] * 3,
    'bank transfer on hold is a success': a.get(str(failures['on_hold'])) == 'bacs:on_hold',
    'three failures open the incident': failing['incidents'] == ['CHECKOUT_PAYMENTS_FAILING:open'],
    'reading again changes nothing': again['attempts'] == failing['attempts'] and again['windows'] == failing['windows'],
    'a paid checkout order resolves it': recovered['attempts'].get(str(paid['paid'])) == 'stripe:paid' and recovered['incidents'] == ['CHECKOUT_PAYMENTS_FAILING:resolved'],
    'coverage observing': recovered['coverage'] == 'observing',
    'no personal data stored': not failing['pii'] and not recovered['pii'],
}
for name, ok in checks.items():
    print(('  PASS ' if ok else '  FAIL ') + name)
sys.exit(0 if all(checks.values()) else 1)
PY
    $DC down -v >/dev/null 2>&1 || true
done

echo "RESULT $([ $STATUS -eq 0 ] && echo PASS || echo FAIL)"
exit $STATUS
