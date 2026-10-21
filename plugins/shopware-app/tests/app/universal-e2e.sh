#!/bin/sh
# The universal Shopware app (ADR 0076) against a real Shopware: one archive for every store; Shopware registers with
# the app secret and the shop stays unbound; the app's main module (opened as the Administration opens it, with the
# query Shopware signs) refuses a code of a store on another domain and binds with the store's code; then orders
# arrive through webhooks and uninstalling the app revokes the integration and forgets the installation.
# Own database (BW_E2E_DATABASE, migrated fresh). Usage: universal-e2e.sh [image ...]
set -eu
cd "$(dirname "$0")"
DOCKER_APP_BIN=/Applications/Docker.app/Contents/Resources/bin
[ -d "$DOCKER_APP_BIN" ] && PATH="$DOCKER_APP_BIN:$PATH"
export PATH
BACKEND_DIR="$(cd ../../../../apps/backend && pwd)"
HELPER="$(pwd)/universal-backend.php"
PHP="${PHP:-/opt/homebrew/opt/php@8.4/bin/php}"
PORT="${BW_E2E_PORT:-8775}"
SERVICE="http://host.docker.internal:${PORT}"
DATABASE="${BW_E2E_DATABASE:-bw_app_universal_e2e}"
ENV="DB_HOST=127.0.0.1 DB_DATABASE=${DATABASE} CACHE_STORE=database QUEUE_CONNECTION=sync APP_URL=${SERVICE} WATCHDOG_DEV_SHOPWARE_ENDPOINT=http://127.0.0.1:8480"
mkdir -p .out
OUT="$(pwd)/.out"
STATUS=0

backend() { (cd "$BACKEND_DIR" && env $ENV "$@" "$PHP" artisan tinker --execute="require '${HELPER}';" 2>/dev/null) | sed -n 's/^BW_E2E_JSON=//p'; }
shop() { python3 -I shop.py "$@" | sed -n 's/^BW_SHOP=//p'; }
j() { python3 -c "import json,sys; print(json.loads(sys.argv[1])$2)" "$1"; }
local_url() { echo "$1" | sed "s#http://host.docker.internal:${PORT}#http://127.0.0.1:${PORT}#"; }
signed_query() { curl -s "$(local_url "$1")" | python3 -c "import sys,re,html; m=re.search(r'name=\"signed_query\" value=\"([^\"]*)\"', sys.stdin.read()); print(html.unescape(m.group(1)) if m else '')"; }
connect() { curl -s -X POST "http://127.0.0.1:${PORT}/api/v1/shopware/app/connect" --data-urlencode "signed_query=$1" --data-urlencode "code=$2" | python3 -c "import sys,re; m=re.search(r'class=\"note (ok|err)\"[^>]*>([^<]*)', sys.stdin.read()); print((m.group(1)+': '+m.group(2)) if m else 'no note')"; }

(cd ../../../.. && docker compose up -d postgres >/dev/null && docker compose exec -T postgres sh -c "psql -U \"\$POSTGRES_USER\" -d postgres -tc \"SELECT 1 FROM pg_database WHERE datname = '${DATABASE}'\" | grep -q 1 || createdb -U \"\$POSTGRES_USER\" ${DATABASE}")
(cd "$BACKEND_DIR" && env $ENV "$PHP" artisan migrate:fresh --force >/dev/null)
(cd "$BACKEND_DIR" && env $ENV "$PHP" artisan serve --host=0.0.0.0 --port="$PORT" > "$OUT/universal-serve.log" 2>&1) &
trap 'pkill -f "artisan serve --host=0.0.0.0 --port=${PORT}" 2>/dev/null || true; docker rm -f bw-shopware >/dev/null 2>&1 || true' EXIT
sleep 2

for IMAGE in ${*:-dockware/play:6.6.10.6 dockware/play:6.7.2.2}; do
    docker rm -f bw-shopware >/dev/null 2>&1 || true
    docker run -d --name bw-shopware -p 127.0.0.1:8480:80 "$IMAGE" >/dev/null
    ../wait-ready.sh
    echo "=== ${IMAGE}"
    package=$(backend BW_E2E_ACTION=universal-package BW_E2E_BASE_URL=https://localhost BW_E2E_ZIP="$OUT/BusinessWatchdog-universal.zip")
    store=$(j "$package" "['store_id']"); other=$(j "$package" "['other_store_id']"); user=$(j "$package" "['user_id']")
    installed=$(shop upload "$OUT/BusinessWatchdog-universal.zip")
    unbound=$(backend BW_E2E_ACTION=state BW_E2E_STORE_ID="$store")
    module=$(shop module)
    source=$(j "$module" "['main_module']['source']" 2>/dev/null || echo "")
    query=$(signed_query "$source")
    wrong=$(connect "$query" "$(j "$(backend BW_E2E_ACTION=code BW_E2E_STORE_ID="$other" BW_E2E_USER_ID="$user")" "['code']")")
    forged=$(connect "$(echo "$query" | sed 's/shop-id=[^&]*/shop-id=forged000000/')" "$(j "$(backend BW_E2E_ACTION=code BW_E2E_STORE_ID="$store" BW_E2E_USER_ID="$user")" "['code']")")
    bound=$(connect "$query" "$(j "$(backend BW_E2E_ACTION=code BW_E2E_STORE_ID="$store" BW_E2E_USER_ID="$user")" "['code']")")
    after_bind=$(backend BW_E2E_ACTION=state BW_E2E_STORE_ID="$store")
    order=$(shop order)
    after_order=$(backend BW_E2E_ACTION=state BW_E2E_STORE_ID="$store")
    removed=$(shop uninstall)
    after_uninstall=$(backend BW_E2E_ACTION=state BW_E2E_STORE_ID="$store")
    for v in installed unbound module wrong forged bound after_bind order after_order after_uninstall; do eval "echo \"  $v: \$$v\"" | cut -c1-400; done
    python3 - "$installed" "$unbound" "$module" "$after_bind" "$order" "$after_order" "$after_uninstall" "$wrong" "$forged" "$bound" <<'PY' || STATUS=1
import json, sys
installed, unbound, module, after_bind, order, after_order, after_uninstall = [json.loads(a) if a else {} for a in sys.argv[1:8]]
wrong, forged, bound = sys.argv[8:11]
checks = {
    'the universal archive installs and the app is active': installed.get('active') is True and installed.get('install') in (200, 204),
    'Shopware registered and confirmed; nothing bound yet': [i['status'] for i in unbound.get('installations', [])] == ['confirmed'] and unbound.get('integration') is None,
    'the Administration opens the main module with a signed source': module.get('status') == 200 and bool((module.get('main_module') or {}).get('source')) and 'shopware-shop-signature=' in (module.get('main_module') or {}).get('source', ''),
    'a code of a store on another domain is refused': wrong.startswith('err:'),
    'a forged shop id is refused': 'opened from' in forged or 'nicht von Ihrer' in forged or forged == 'no note',
    'the store\'s code binds the installation': bound.startswith('ok:'),
    'bound: active app integration with keys': after_bind.get('integration') == 'active' and after_bind.get('connection') == 'app' and 'shopware_integration' in after_bind.get('active_credentials', []) and [i['status'] for i in after_bind['installations']] == ['bound'],
    'an order arrives through the webhook': order.get('number') in (after_order.get('orders') or {}),
    'uninstalling revokes the integration and forgets the installation': after_uninstall.get('integration') == 'revoked' and after_uninstall.get('installations') == [],
}
for name, ok in checks.items():
    print(('  PASS ' if ok else '  FAIL ') + name)
sys.exit(0 if all(checks.values()) else 1)
PY
done

echo "RESULT $([ $STATUS -eq 0 ] && echo PASS || echo FAIL)"
exit $STATUS
