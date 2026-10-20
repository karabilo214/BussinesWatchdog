#!/bin/sh
# Updating the Shopware app of a connected store (ADR 0061) against a real Shopware: an older app (1.0.0, without the
# checkout observation files) is installed and connected; the dashboard's update archive for the same integration is
# uploaded as an update; the integration stays the same and active, the shop reports the new version (app.updated),
# the storefront script arrives without checkout template overrides, and orders keep coming through webhooks. Own database
# (BW_E2E_DATABASE, migrated fresh). Usage: update-e2e.sh [image ...]
set -eu
cd "$(dirname "$0")"
DOCKER_APP_BIN=/Applications/Docker.app/Contents/Resources/bin
[ -d "$DOCKER_APP_BIN" ] && PATH="$DOCKER_APP_BIN:$PATH"
export PATH
BACKEND_DIR="$(cd ../../../../apps/backend && pwd)"
CHAIN_HELPER="$(cd ../chain && pwd)/backend-e2e.php"
HELPER="$(pwd)/update-backend.php"
PHP="${PHP:-/opt/homebrew/opt/php@8.4/bin/php}"
PORT="${BW_E2E_PORT:-8773}"
SERVICE="http://host.docker.internal:${PORT}"
DATABASE="${BW_E2E_DATABASE:-bw_app_update_e2e}"
ENV="DB_HOST=127.0.0.1 DB_DATABASE=${DATABASE} CACHE_STORE=database QUEUE_CONNECTION=sync APP_URL=${SERVICE} WATCHDOG_DEV_SHOPWARE_ENDPOINT=http://127.0.0.1:8480"
mkdir -p .out
OUT="$(pwd)/.out"
STATUS=0

chain() { (cd "$BACKEND_DIR" && env $ENV "$@" "$PHP" artisan tinker --execute="require '${CHAIN_HELPER}';" 2>/dev/null) | sed -n 's/^BW_E2E_JSON=//p'; }
backend() { (cd "$BACKEND_DIR" && env $ENV "$@" "$PHP" artisan tinker --execute="require '${HELPER}';" 2>/dev/null) | sed -n 's/^BW_E2E_JSON=//p'; }
shop() { python3 -I shop.py "$@" | sed -n 's/^BW_SHOP=//p'; }
j() { python3 -c "import json,sys; print(json.loads(sys.argv[1])$2)" "$1"; }

(cd ../../../.. && docker compose up -d postgres >/dev/null && docker compose exec -T postgres sh -c "psql -U \"\$POSTGRES_USER\" -d postgres -tc \"SELECT 1 FROM pg_database WHERE datname = '${DATABASE}'\" | grep -q 1 || createdb -U \"\$POSTGRES_USER\" ${DATABASE}")
(cd "$BACKEND_DIR" && env $ENV "$PHP" artisan serve --host=0.0.0.0 --port="$PORT" > "$OUT/update-serve.log" 2>&1) &
trap 'pkill -f "artisan serve --host=0.0.0.0 --port=${PORT}" 2>/dev/null || true; docker rm -f bw-shopware >/dev/null 2>&1 || true' EXIT
sleep 2

for IMAGE in ${*:-dockware/play:6.6.10.6 dockware/play:6.7.2.2}; do
    (cd "$BACKEND_DIR" && env $ENV "$PHP" artisan migrate:fresh --force >/dev/null)
    docker rm -f bw-shopware >/dev/null 2>&1 || true
    docker run -d --name bw-shopware -p 127.0.0.1:8480:80 "$IMAGE" >/dev/null
    ../wait-ready.sh
    echo "=== ${IMAGE}"
    package=$(chain BW_E2E_ACTION=app-package BW_E2E_BASE_URL=https://localhost BW_E2E_ZIP="$OUT/BusinessWatchdog.zip")
    store=$(j "$package" "['store_id']")
    backend BW_E2E_ACTION=old-archive BW_E2E_STORE_ID="$store" BW_E2E_ZIP="$OUT/BusinessWatchdog-old.zip" >/dev/null
    installed=$(shop upload "$OUT/BusinessWatchdog-old.zip")
    shop order >/dev/null
    before=$(backend BW_E2E_ACTION=state BW_E2E_STORE_ID="$store")
    expected=$(j "$(backend BW_E2E_ACTION=update-archive BW_E2E_STORE_ID="$store" BW_E2E_ZIP="$OUT/BusinessWatchdog-update.zip")" "['version']")
    updated=$(shop update "$OUT/BusinessWatchdog-update.zip")
    docker exec bw-shopware bash -lc 'cd /var/www/html && php bin/console cache:clear >/dev/null 2>&1'
    after=$(backend BW_E2E_ACTION=state BW_E2E_STORE_ID="$store")
    order=$(shop order)
    after_order=$(backend BW_E2E_ACTION=state BW_E2E_STORE_ID="$store")
    files=$(docker exec bw-shopware bash -lc 'cd /var/www/html && echo "{\"script\": $(find public/theme -path "*business-watchdog/business-watchdog.js" | wc -l), \"templates\": $(mysql -h127.0.0.1 -uroot -proot shopware -N -e "select count(*) from app_template t join app a on a.id = t.app_id where a.name = '"'"'BusinessWatchdog'"'"'" 2>/dev/null), \"scripts\": $(mysql -h127.0.0.1 -uroot -proot shopware -N -e "select count(*) from script s join app a on a.id = s.app_id where a.name = '"'"'BusinessWatchdog'"'"'" 2>/dev/null), \"entities\": $(mysql -h127.0.0.1 -uroot -proot shopware -N -e "select count(*) from information_schema.tables where table_schema = '"'"'shopware'"'"' and table_name like '"'"'custom_entity_bw_checkout_%'"'"'" 2>/dev/null)}"')
    for v in installed before updated after order after_order files; do eval "echo \"  $v: \$$v\""; done
    python3 - "$installed" "$before" "$updated" "$after" "$order" "$after_order" "$files" "$expected" <<'PY' || STATUS=1
import json, sys
installed, before, updated, after, order, after_order, files = [json.loads(a) for a in sys.argv[1:8]]
expected = sys.argv[8]
checks = {
    'old app 1.0.0 installed and connected': installed.get('active') is True and before['status'] == 'active',
    'the service knows the shop runs 1.0.0 and offers the update': before['app_update']['installed_version'] == '1.0.0' and before['app_update']['available'] is True,
    'Shopware accepted the archive as an update': updated.get('update') in (200, 204) and updated.get('version') == expected and updated.get('active') is True,
    'same integration, still active, still one': after['integration_id'] == before['integration_id'] and after['status'] == 'active' and after['integrations'] == 1,
    'the new version is known at once (app.updated)': after['app_update']['installed_version'] == expected and after['app_update']['available'] is False,
    'the API key still works: orders keep arriving through webhooks': order.get('number') in after_order['orders'] and after_order['status'] == 'active',
    'storefront script arrived without checkout template overrides': files['script'] >= 1 and files['templates'] == 0,
    'observation endpoint and entities arrived without page-loaded scripts': files['scripts'] == 1 and files['entities'] == 2,
}
print('  registrations before/after:', before['registrations'], after['registrations'], '| credentials:', after['active_credentials'])
for name, ok in checks.items():
    print(('  PASS ' if ok else '  FAIL ') + name)
sys.exit(0 if all(checks.values()) else 1)
PY
done

echo "RESULT $([ $STATUS -eq 0 ] && echo PASS || echo FAIL)"
exit $STATUS
