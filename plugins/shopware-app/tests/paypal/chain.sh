#!/bin/sh
# Real PayPal sandbox money through SwagPayPal into the service (ADR 0025/0026): three USD payments by the sandbox
# buyer (partial refund, full refund, no refund, refunds made in the shop's PayPal tab), then the backend connects the
# store's Shopware integration key and the owner's Watchdog PayPal app, syncs both, links by capture id and checks
# refunds that Shopware keeps only as a payment state. Needs bw-shopware (6.6 or 6.7) with SwagPayPal installed.
set -eu
cd "$(dirname "$0")"
BACKEND_DIR="$(cd ../../../../apps/backend && pwd)"
HELPER="$(cd ../chain && pwd)/backend-e2e.php"
PHP="${PHP:-/opt/homebrew/opt/php@8.4/bin/php}"
ENV_FILE="$BACKEND_DIR/.env"
value() { grep "^$1=" "$ENV_FILE" | cut -d= -f2-; }
backend() { (cd "$BACKEND_DIR" && env DB_HOST=127.0.0.1 CACHE_STORE=database WATCHDOG_DEV_SHOPWARE_ENDPOINT=http://127.0.0.1:8480 "$@" "$PHP" artisan tinker --execute="require '${HELPER}';" 2>/dev/null) | sed -n 's/^BW_E2E_JSON=//p'; }

BW_PAYPAL_CLIENT_ID="$(value WATCHDOG_DEV_PAYPAL_SHOP_CLIENT_ID)" BW_PAYPAL_CLIENT_SECRET="$(value WATCHDOG_DEV_PAYPAL_SHOP_CLIENT_SECRET)" python3 -I configure.py
numbers="{"
for label in partial full none; do
    BW_SW_CURRENCY=USD ./pay.sh > ".out/pay-${label}.log" 2>&1 || { echo "payment ${label} failed"; tail -3 ".out/pay-${label}.log"; exit 1; }
    order=$(python3 -c "import json; print(json.load(open('.order.json'))['id'])")
    total=$(python3 -c "import json; print(json.load(open('.order.json'))['total'])")
    number=$(python3 -c "import json; print(json.load(open('.order.json'))['number'])")
    case $label in
        partial) python3 -I refund.py "$order" 5.00 >/dev/null ;;
        full) python3 -I refund.py "$order" "$total" >/dev/null ;;
    esac
    echo "paid ${label}: order ${number} $(tail -1 ".out/pay-${label}.log" | cut -c1-90)"
    numbers="${numbers}\"${label}\": \"${number}\","
done
numbers="${numbers%,}}"
keys=$(python3 -I -c "
import sys; sys.path.insert(0, '../spike')
import shopware_spike as s, json
k, _ = s.setup_integration(s.admin_token())
print(json.dumps(k))" | tail -1)
setup=$(backend BW_E2E_ACTION=paypal-setup BW_E2E_BASE_URL=https://shop-sw.example.com BW_E2E_ACCESS_KEY="$(echo "$keys" | python3 -c "import json,sys; print(json.load(sys.stdin)['accessKey'])")" BW_E2E_SECRET="$(echo "$keys" | python3 -c "import json,sys; print(json.load(sys.stdin)['secretAccessKey'])")")
[ -n "$setup" ] || { echo "backend setup failed"; exit 1; }
store_id=$(echo "$setup" | python3 -c "import json,sys; print(json.load(sys.stdin)['store_id'])")
check=$(backend BW_E2E_ACTION=paypal-check BW_E2E_STORE_ID="$store_id" BW_E2E_NUMBERS="$numbers")
backend BW_E2E_ACTION=cleanup BW_E2E_STORE_ID="$store_id" >/dev/null
python3 - "$setup" "$check" <<'PY'
import json, sys
setup, check = json.loads(sys.argv[1]), json.loads(sys.argv[2])
print('  setup:', setup)
print('  shopware sync:', check['shopware_sync'], '| paypal pointers:', check['pointer_sync'])
for label, row in check['orders'].items():
    print(f"  {label}: {row['order']} links={row['links']} findings={row['findings']}")
o = check['orders']
def has(label, prefix):
    return any(f.startswith(prefix) for f in o[label]['findings'])
checks = {
    'orders carry the capture id, PayPal coverage and test mode': all('|swag_paypal_paypal|test|supported|' in o[l]['order'] and o[l]['order'].endswith('|ref') for l in o),
    'captures linked by the exact capture id': all(len(o[l]['links']) == 1 and o[l]['links'][0].startswith('exact_reference:') for l in o),
    'capture amounts ok': all(has(l, 'MONEY_CAPTURE_AMOUNT:ok') for l in o),
    'partial refund: unknown with the provider amount 500': has('partial', 'MONEY_REFUND_MISSING:unknown:refund_amount_not_reported:500'),
    'full refund: reconciled with the provider': has('full', 'MONEY_REFUND_MISSING:ok:refund_reconciled_ok'),
    'no refund: no refund finding': not any(f.startswith('MONEY_REFUND') for f in o['none']['findings']),
}
for name, ok in checks.items():
    print(('  PASS ' if ok else '  FAIL ') + name)
print('RESULT', 'PASS' if all(checks.values()) else 'FAIL')
sys.exit(0 if all(checks.values()) else 1)
PY
