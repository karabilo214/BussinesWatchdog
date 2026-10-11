#!/bin/sh
# WooCommerce REST API connector against a real store (ADR 0024): a matrix WooCommerce without the Watchdog plugin,
# a Read key and a Read/Write key created as in the WooCommerce admin; the backend connects (the Read/Write key must be
# refused), syncs, then the store changes (late refund, deleted order, trashed order) and recent/audit runs must see
# it. The backend reaches the container through a published port with the store's host name.
set -eu

cd "$(dirname "$0")"
DOCKER_APP_BIN=/Applications/Docker.app/Contents/Resources/bin
[ -d "$DOCKER_APP_BIN" ] && PATH="$DOCKER_APP_BIN:$PATH"
BACKEND_DIR="$(cd ../../../../apps/backend && pwd)"
HELPER="$(pwd)/backend-e2e.php"
PHP="${PHP:-/opt/homebrew/opt/php@8.4/bin/php}"
STATUS=0

for TARGET in ${*:-floor latest}; do
    BW_MATRIX_KEEP=1 BW_TEST_FILTER=install ./run.sh "$TARGET" >/dev/null 2>&1 || { echo "matrix setup failed"; exit 1; }
    DC="docker compose -p bwmatrix-${TARGET} -f docker-compose.yml"
    WP="$DC exec -T -u www-data wordpress php /bw-cache/wp-cli.phar --path=/var/www/html"
    $WP plugin deactivate business-watchdog >/dev/null
    $WP rewrite structure '/%postname%/' --hard >/dev/null 2>&1
    PORT=$($DC port wordpress 80 | cut -d: -f2)
    store() { $DC exec -T -u www-data -e BW_STEP="$1" -e BW_ORDERS="${2:-}" wordpress php /bw-cache/wp-cli.phar --path=/var/www/html eval-file /var/www/html/wp-content/plugins/business-watchdog/tests/matrix/rest-api-chain-store.php 2>/dev/null | sed -n 's/^BW_STORE=//p'; }
    backend() { (cd "$BACKEND_DIR" && env DB_HOST=127.0.0.1 CACHE_STORE=database WATCHDOG_DEV_WC_API_ENDPOINT="http://127.0.0.1:${PORT}" "$@" "$PHP" artisan tinker --execute="require '${HELPER}';" 2>/dev/null) | sed -n 's/^BW_E2E_JSON=//p'; }
    j() { python3 -c "import json,sys; print(json.loads(sys.argv[1])$2)" "$1"; }

    echo "=== ${TARGET} ($(grep "^${TARGET}=" targets.env | cut -d= -f2-)), Watchdog plugin not active"
    shop=$(store setup)
    setup=$(backend BW_E2E_ACTION=rest-api-setup BW_E2E_BASE_URL="https://shop-${TARGET}.example.test" \
        BW_E2E_READ_KEY="$(j "$shop" "['read']['key']")" BW_E2E_READ_SECRET="$(j "$shop" "['read']['secret']")" \
        BW_E2E_WRITE_KEY="$(j "$shop" "['read_write']['key']")" BW_E2E_WRITE_SECRET="$(j "$shop" "['read_write']['secret']")")
    [ -n "$setup" ] || { echo "backend connect failed"; STATUS=1; continue; }
    store_id=$(j "$setup" "['store_id']")
    first=$(backend BW_E2E_ACTION=rest-api-sync BW_E2E_STORE_ID="$store_id" BW_E2E_MODE=delta)
    second=$(backend BW_E2E_ACTION=rest-api-sync BW_E2E_STORE_ID="$store_id" BW_E2E_MODE=delta)
    change=$(store change "$(python3 -c "import json,sys; print(json.dumps(json.loads(sys.argv[1])['orders']))" "$shop")")
    delta=$(backend BW_E2E_ACTION=rest-api-sync BW_E2E_STORE_ID="$store_id" BW_E2E_MODE=delta)
    recent=$(backend BW_E2E_ACTION=rest-api-sync BW_E2E_STORE_ID="$store_id" BW_E2E_MODE=recent)
    audit=$(backend BW_E2E_ACTION=rest-api-sync BW_E2E_STORE_ID="$store_id" BW_E2E_MODE=audit)
    backend BW_E2E_ACTION=rest-api-cleanup BW_E2E_STORE_ID="$store_id" >/dev/null
    n=0; for v in "$shop" "$setup" "$first" "$second" "$change" "$delta" "$recent" "$audit"; do n=$((n + 1)); [ -n "$v" ] || { echo "  empty result of step $n"; STATUS=1; }; done
    python3 - "$shop" "$setup" "$first" "$second" "$change" "$delta" "$recent" "$audit" <<'PY' || STATUS=1
import json, sys
shop, setup, first, second, change, delta, recent, audit = [json.loads(a) for a in sys.argv[1:]]
o = {k: str(v) for k, v in shop['orders'].items()}
late = str(change['late_refund'])
print('  connect: read key', setup['status'], '| read/write key', setup['write_key'])
for label, run in [('first delta', first), ('second delta', second), ('delta after changes', delta), ('recent', recent), ('audit', audit)]:
    print(f"  {label}: {run['sync']} freshness={run['freshness']} inbox={run['inbox']}")
print('  orders:', audit['orders'])
print('  refunds:', audit['refunds'])
checks = {
    'read/write key refused': setup['write_key'] == 'woocommerce_key_not_read_only',
    'read key connected': setup['status'] == 'active',
    'orders mapped': first['orders'].get(o['stripe'], '').startswith('processing|EUR|18400|stripe|live|supported|ref')
        and first['orders'].get(o['paypal'], '').startswith('processing|USD|1000|ppcp-gateway|test|supported|ref')
        and first['orders'].get(o['yen'], '').startswith('on-hold|JPY|1500|bacs'),
    'refund with provider id': first['refunds'].get(str(shop['orders']['refund'])) == 'recorded|3000|re_3ChainRefund01',
    'no personal data in events': not any(r['pii_in_inbox'] for r in [first, audit]),
    'second delta emits nothing': second['sync']['emitted'] == 0,
    'late refund found': (delta['refunds'].get(late) or recent['refunds'].get(late)) == 'recorded|400|-',
    'trashed order kept as trash': audit['orders'].get(o['trash'], '').startswith('trash|'),
    'deleted order found by the audit': '|deleted|' in audit['orders'].get(o['doomed'], ''),
    'all events processed': set(audit['inbox']) == {'processed'},
    'connector fresh': audit['freshness'] == 'fresh',
}
for name, ok in checks.items():
    print(('  PASS ' if ok else '  FAIL ') + name)
late_where = 'delta' if delta['refunds'].get(late) else ('recent' if recent['refunds'].get(late) else 'none')
print('  late refund first seen by:', late_where)
sys.exit(0 if all(checks.values()) else 1)
PY
    [ "${BW_MATRIX_KEEP:-0}" = "1" ] || WP_IMAGE=unused $DC down -v >/dev/null 2>&1 || true
done

echo "RESULT $([ $STATUS -eq 0 ] && echo PASS || echo FAIL)"
exit $STATUS
