#!/bin/sh
# Shopware App end to end (ADR 0027): the service builds the store's app archive, the owner uploads and installs it in
# a real Shopware, Shopware registers with the backend (handshake, confirmation with the app key), then an order and
# its payment arrive through signed webhooks only (no polling), and uninstalling the app revokes the integration.
# Usage: app-e2e.sh [image ...]
set -eu
cd "$(dirname "$0")"
DOCKER_APP_BIN=/Applications/Docker.app/Contents/Resources/bin
[ -d "$DOCKER_APP_BIN" ] && PATH="$DOCKER_APP_BIN:$PATH"
export PATH
BACKEND_DIR="$(cd ../../../../apps/backend && pwd)"
HELPER="$(cd ../chain && pwd)/backend-e2e.php"
PHP="${PHP:-/opt/homebrew/opt/php@8.4/bin/php}"
PORT="${BW_E2E_PORT:-8769}"
SERVICE="http://host.docker.internal:${PORT}"
STATUS=0
mkdir -p .out
OUT="$(pwd)/.out"
backend() { (cd "$BACKEND_DIR" && env DB_HOST=127.0.0.1 CACHE_STORE=database APP_URL="$SERVICE" WATCHDOG_DEV_SHOPWARE_ENDPOINT=http://127.0.0.1:8480 "$@" "$PHP" artisan tinker --execute="require '${HELPER}';" 2>/dev/null) | sed -n 's/^BW_E2E_JSON=//p'; }
shop() { python3 -I shop.py "$@" | sed -n 's/^BW_SHOP=//p'; }

(cd "$BACKEND_DIR" && DB_HOST=127.0.0.1 CACHE_STORE=database APP_URL="$SERVICE" WATCHDOG_DEV_SHOPWARE_ENDPOINT=http://127.0.0.1:8480 "$PHP" artisan serve --host=0.0.0.0 --port="$PORT" > "$OUT/serve.log" 2>&1) &
trap 'pkill -f "artisan serve --host=0.0.0.0 --port=${PORT}" 2>/dev/null || true' EXIT
sleep 2

for IMAGE in ${*:-dockware/play:6.6.10.6 dockware/play:6.7.2.2}; do
    docker rm -f bw-shopware >/dev/null 2>&1 || true
    docker run -d --name bw-shopware -p 127.0.0.1:8480:80 "$IMAGE" >/dev/null
    i=0; until [ "$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8480/api/_info/version)" = "401" ]; do i=$((i + 1)); [ $i -gt 60 ] && { echo "shopware did not start"; exit 1; }; sleep 5; done
    echo "=== ${IMAGE}"
    package=$(backend BW_E2E_ACTION=app-package BW_E2E_BASE_URL=https://localhost BW_E2E_ZIP="$(pwd)/.out/BusinessWatchdog.zip")
    store_id=$(echo "$package" | python3 -c "import json,sys; print(json.load(sys.stdin)['store_id'])")
    installed=$(shop upload "$(pwd)/.out/BusinessWatchdog.zip")
    after_install=$(backend BW_E2E_ACTION=app-state BW_E2E_STORE_ID="$store_id")
    order=$(shop order)
    after_order=$(backend BW_E2E_ACTION=app-state BW_E2E_STORE_ID="$store_id")
    paid=$(shop pay "$(echo "$order" | python3 -c "import json,sys; print(json.load(sys.stdin)['transaction'])")")
    after_pay=$(backend BW_E2E_ACTION=app-state BW_E2E_STORE_ID="$store_id")
    removed=$(shop uninstall)
    after_uninstall=$(backend BW_E2E_ACTION=app-state BW_E2E_STORE_ID="$store_id")
    python3 - "$package" "$installed" "$after_install" "$order" "$after_order" "$paid" "$after_pay" "$removed" "$after_uninstall" <<'PY' || STATUS=1
import json, sys
package, installed, after_install, order, after_order, paid, after_pay, removed, after_uninstall = [json.loads(a) if a else {} for a in sys.argv[1:]]
number = order.get('number')
print('  package:', package.get('status'), '| shop install:', {k: v for k, v in installed.items() if k != 'privileges'})
print('  app privileges in Shopware:', installed.get('privileges'))
print('  after install:', {k: after_install.get(k) for k in ['status', 'shop_url', 'store_verified', 'verification_method', 'active_credentials']})
print('  after order:', after_order.get('orders'), 'webhook', after_order.get('last_webhook'), 'last_sync', after_order.get('last_sync'))
print('  after pay:', after_pay.get('orders'), 'webhook', after_pay.get('last_webhook'))
print('  after uninstall:', after_uninstall.get('status'), after_uninstall.get('active_credentials'), 'webhook', after_uninstall.get('last_webhook'))
checks = {
    'archive accepted and app active in Shopware': installed.get('active') is True and installed.get('install') in (200, 204),
    'app has only read privileges': bool(installed.get('privileges')) and all(p.endswith(':read') for p in installed.get('privileges', [])),
    'registration and confirmation made the app the order source': after_install.get('status') == 'active' and 'shopware_integration' in after_install.get('active_credentials', []),
    'store confirmed by the app': after_install.get('store_verified') is True and after_install.get('verification_method') == 'api_key',
    'order arrived through the webhook without polling': number in (after_order.get('orders') or {}) and after_order.get('last_sync') is None,
    'payment arrived through the webhook': '|paid|' in (after_pay.get('orders') or {}).get(number, ''),
    'uninstalling the app revoked the integration': after_uninstall.get('status') == 'revoked' and not after_uninstall.get('active_credentials'),
}
for name, ok in checks.items():
    print(('  PASS ' if ok else '  FAIL ') + name)
sys.exit(0 if all(checks.values()) else 1)
PY
done

docker rm -f bw-shopware >/dev/null 2>&1 || true
echo "RESULT $([ $STATUS -eq 0 ] && echo PASS || echo FAIL)"
exit $STATUS
