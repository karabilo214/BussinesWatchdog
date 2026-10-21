#!/bin/sh
# WooCommerce's own order webhooks for a REST API connection (ADR 0077) against a real store without the Watchdog
# plugin: the service gives a delivery URL and a secret, WooCommerce gets four webhooks as the merchant creates them,
# saves (ping), then a new order and its status change are delivered, signed, and make the service read the order
# without waiting for polling. Own database (BW_E2E_DATABASE, migrated fresh). Usage: rest-api-webhook-e2e.sh [target]
set -eu

cd "$(dirname "$0")"
DOCKER_APP_BIN=/Applications/Docker.app/Contents/Resources/bin
[ -d "$DOCKER_APP_BIN" ] && PATH="$DOCKER_APP_BIN:$PATH"
BACKEND_DIR="$(cd ../../../../apps/backend && pwd)"
CHAIN="$(pwd)/backend-e2e.php"
HELPER="$(pwd)/rest-api-webhook-backend.php"
PHP="${PHP:-/opt/homebrew/opt/php@8.4/bin/php}"
TARGET="${1:-wc9}"
SERVICE_PORT="${BW_E2E_PORT:-8777}"
DATABASE="${BW_E2E_DATABASE:-bw_woo_webhook_e2e}"
STATUS=0
LOG="$(pwd)/.cache/webhook-serve.log"

BW_MATRIX_KEEP=1 BW_TEST_FILTER=install ./run.sh "$TARGET" >/dev/null 2>&1 || { echo "matrix setup failed"; exit 1; }
WP_IMAGE=$(grep "^${TARGET}=" targets.env | cut -d= -f2- | cut -d"|" -f1)
export WP_IMAGE
DC="docker compose -p bwmatrix-${TARGET} -f docker-compose.yml"
WP="$DC exec -T -u www-data wordpress php /bw-cache/wp-cli.phar --path=/var/www/html"
$WP plugin deactivate business-watchdog >/dev/null
$WP rewrite structure '/%postname%/' --hard >/dev/null 2>&1
$WP option update bw_e2e_allow_local_webhooks 1 >/dev/null
WC_PORT=$($DC port wordpress 80 | cut -d: -f2)
ENV="DB_HOST=127.0.0.1 DB_DATABASE=${DATABASE} CACHE_STORE=database QUEUE_CONNECTION=sync APP_URL=http://host.docker.internal:${SERVICE_PORT} WATCHDOG_DEV_WC_API_ENDPOINT=http://127.0.0.1:${WC_PORT}"
store() { $DC exec -T -u www-data -e BW_STEP="$1" -e BW_ORDERS="${2:-}" wordpress php /bw-cache/wp-cli.phar --path=/var/www/html eval-file /var/www/html/wp-content/plugins/business-watchdog/tests/matrix/rest-api-chain-store.php 2>/dev/null | sed -n 's/^BW_STORE=//p'; }
chain() { (cd "$BACKEND_DIR" && env $ENV "$@" "$PHP" artisan tinker --execute="require '${CHAIN}';" 2>/dev/null) | sed -n 's/^BW_E2E_JSON=//p'; }
backend() { (cd "$BACKEND_DIR" && env $ENV "$@" "$PHP" artisan tinker --execute="require '${HELPER}';" 2>/dev/null) | sed -n 's/^BW_E2E_JSON=//p'; }
j() { python3 -c "import json,sys; print(json.loads(sys.argv[1])$2)" "$1"; }

(cd ../../../.. && docker compose up -d postgres >/dev/null && docker compose exec -T postgres sh -c "psql -U \"\$POSTGRES_USER\" -d postgres -tc \"SELECT 1 FROM pg_database WHERE datname = '${DATABASE}'\" | grep -q 1 || createdb -U \"\$POSTGRES_USER\" ${DATABASE}")
(cd "$BACKEND_DIR" && env $ENV "$PHP" artisan migrate:fresh --force >/dev/null)
(cd "$BACKEND_DIR" && env $ENV "$PHP" artisan serve --host=0.0.0.0 --port="$SERVICE_PORT" > "$LOG" 2>&1) &
trap 'pkill -f "artisan serve --host=0.0.0.0 --port=${SERVICE_PORT}" 2>/dev/null || true; $WP option delete bw_e2e_allow_local_webhooks >/dev/null 2>&1 || true; [ "${BW_MATRIX_KEEP:-0}" = "1" ] || WP_IMAGE=unused $DC down -v >/dev/null 2>&1 || true' EXIT
sleep 2

echo "=== ${TARGET} ($(grep "^${TARGET}=" targets.env | cut -d= -f2-)), Watchdog plugin not active"
shop=$(store setup)
setup=$(chain BW_E2E_ACTION=rest-api-setup BW_E2E_BASE_URL="https://shop-${TARGET}.example.test" \
    BW_E2E_READ_KEY="$(j "$shop" "['read']['key']")" BW_E2E_READ_SECRET="$(j "$shop" "['read']['secret']")" \
    BW_E2E_WRITE_KEY="$(j "$shop" "['read_write']['key']")" BW_E2E_WRITE_SECRET="$(j "$shop" "['read_write']['secret']")")
store_id=$(j "$setup" "['store_id']")
webhook=$(backend BW_E2E_ACTION=webhook-setup BW_E2E_STORE_ID="$store_id")
url=$(j "$webhook" "['delivery_url']"); secret=$(j "$webhook" "['secret']")
ids=""
for topic in order.created order.updated order.deleted order.restored; do
    id=$($WP wc webhook create --user=admin --name="Business Watchdog ${topic}" --topic="$topic" --delivery_url="$url" --secret="$secret" --status=active --porcelain 2>/dev/null)
    ids="$ids $id"
done
before=$(backend BW_E2E_ACTION=webhook-state BW_E2E_STORE_ID="$store_id")
order=$($WP eval '$o = wc_create_order(); $o->set_currency("EUR"); $o->set_total("42.00"); $o->set_payment_method("bacs"); $o->save(); $o->update_status("processing"); echo $o->get_id();' 2>/dev/null | tail -1)
$WP action-scheduler run >/dev/null 2>&1 || true
after=$(backend BW_E2E_ACTION=webhook-state BW_E2E_STORE_ID="$store_id")
failures=""
for id in $ids; do failures="$failures $($WP eval "echo wc_get_webhook($id)->get_failure_count();" 2>/dev/null || echo x)"; done
echo "  webhook ids:$ids | failures:$failures"
echo "  before: $before"
echo "  order: $order"
echo "  after: $after"
python3 - "$setup" "$before" "$after" "$order" "$failures" <<'PY' || STATUS=1
import json, sys
setup, before, after = [json.loads(a) for a in sys.argv[1:4]]
order, failures = sys.argv[4], sys.argv[5].split()
checks = {
    'read key connected': setup['status'] == 'active',
    'four webhooks created in WooCommerce': len(failures) == 4,
    'no failed deliveries (ping and orders answered 200)': failures == ['0', '0', '0', '0'],
    'nothing synced before the order': before['last_sync'] is None and not before['orders'],
    'a signed order delivery arrived': (after['webhook'] or {}).get('last_type') in ('order.created', 'order.updated'),
    'the new order was read through the API at once': order in after['orders'] and after['last_sync'] is not None,
}
for name, ok in checks.items():
    print(('  PASS ' if ok else '  FAIL ') + name)
sys.exit(0 if all(checks.values()) else 1)
PY

echo "RESULT $([ $STATUS -eq 0 ] && echo PASS || echo FAIL)"
exit $STATUS
