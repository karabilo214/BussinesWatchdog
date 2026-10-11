#!/bin/sh
# Shopware connector against a real Shopware (ADR 0026): a read-only Integration created as an owner would, orders
# through the Store API; the backend connects (the risk must be acknowledged), syncs, then the admin pays, partially
# refunds, moves and deletes orders, and delta and audit runs must see it. Usage: chain-e2e.sh [image ...]
set -eu
cd "$(dirname "$0")"
DOCKER_APP_BIN=/Applications/Docker.app/Contents/Resources/bin
[ -d "$DOCKER_APP_BIN" ] && PATH="$DOCKER_APP_BIN:$PATH"
BACKEND_DIR="$(cd ../../../../apps/backend && pwd)"
HELPER="$(pwd)/backend-e2e.php"
PHP="${PHP:-/opt/homebrew/opt/php@8.4/bin/php}"
STATUS=0

backend() { (cd "$BACKEND_DIR" && env DB_HOST=127.0.0.1 CACHE_STORE=database WATCHDOG_DEV_SHOPWARE_ENDPOINT=http://127.0.0.1:8480 "$@" "$PHP" artisan tinker --execute="require '${HELPER}';" 2>/dev/null) | sed -n 's/^BW_E2E_JSON=//p'; }

for IMAGE in ${*:-dockware/play:6.6.10.6 dockware/play:6.7.2.2}; do
    docker rm -f bw-shopware >/dev/null 2>&1 || true
    docker run -d --name bw-shopware -p 127.0.0.1:8480:80 "$IMAGE" >/dev/null
    ../wait-ready.sh
    echo "=== ${IMAGE}"
    shop=$(python3 -I store.py setup | sed -n 's/^BW_STORE=//p')
    j() { python3 -c "import json,sys; print(json.loads(sys.argv[1])$2)" "$1"; }
    setup=$(backend BW_E2E_ACTION=setup BW_E2E_BASE_URL="https://shopware-e2e.example.test" BW_E2E_ACCESS_KEY="$(j "$shop" "['access_key']")" BW_E2E_SECRET="$(j "$shop" "['secret']")")
    [ -n "$setup" ] || { echo "backend connect failed"; STATUS=1; continue; }
    store_id=$(j "$setup" "['store_id']")
    first=$(backend BW_E2E_ACTION=sync BW_E2E_STORE_ID="$store_id" BW_E2E_MODE=delta)
    second=$(backend BW_E2E_ACTION=sync BW_E2E_STORE_ID="$store_id" BW_E2E_MODE=delta)
    change=$(python3 -I store.py change "$(python3 -c "import json,sys; print(json.dumps(json.loads(sys.argv[1])['orders']))" "$shop")" | sed -n 's/^BW_STORE=//p')
    delta=$(backend BW_E2E_ACTION=sync BW_E2E_STORE_ID="$store_id" BW_E2E_MODE=delta)
    audit=$(backend BW_E2E_ACTION=sync BW_E2E_STORE_ID="$store_id" BW_E2E_MODE=audit)
    backend BW_E2E_ACTION=cleanup BW_E2E_STORE_ID="$store_id" >/dev/null
    n=0; for v in "$shop" "$setup" "$first" "$second" "$change" "$delta" "$audit"; do n=$((n + 1)); [ -n "$v" ] || { echo "  empty result of step $n"; STATUS=1; }; done
    python3 - "$shop" "$setup" "$first" "$second" "$change" "$delta" "$audit" <<'PY' || STATUS=1
import json, sys
shop, setup, first, second, change, delta, audit = [json.loads(a) for a in sys.argv[1:]]
paid, refunded, doomed = [o['number'] for o in shop['orders']]
totals = [str(round(o['total'] * 100)) for o in shop['orders']]
print('  connect:', setup, '| admin changes:', change)
for label, run in [('first delta', first), ('second delta', second), ('delta after changes', delta), ('audit', audit)]:
    print(f"  {label}: {run['sync']} freshness={run['freshness']} inbox={run['inbox']}")
print('  orders:', audit['orders'])
checks = {
    'connect refused without the acknowledgement': setup['without_acknowledgement'] == 'shopware_state_change_risk_not_acknowledged',
    'connected and store confirmed': setup['status'] == 'active' and setup['store_verified'],
    'orders mapped': all(first['orders'].get(n, '').startswith(f'open|EUR|{t}|payment_prepayment|unsupported|none|unpaid') for n, t in zip([paid, refunded, doomed], totals)),
    'no personal data in events': not first['pii_in_inbox'] and not audit['pii_in_inbox'],
    'second delta emits nothing': second['sync']['emitted'] == 0,
    'payment state change seen by the delta': delta['orders'][paid].startswith('in_progress|') and '|paid|' in delta['orders'][paid],
    'partial refund state seen': '|partial|paid|' in delta['orders'][refunded],
    'deleted order found by the audit': '|deleted|' in audit['orders'][doomed],
    'all events processed': set(audit['inbox']) == {'processed'},
    'connector fresh': audit['freshness'] == 'fresh',
}
for name, ok in checks.items():
    print(('  PASS ' if ok else '  FAIL ') + name)
sys.exit(0 if all(checks.values()) else 1)
PY
done

docker rm -f bw-shopware >/dev/null 2>&1 || true
echo "RESULT $([ $STATUS -eq 0 ] && echo PASS || echo FAIL)"
exit $STATUS
