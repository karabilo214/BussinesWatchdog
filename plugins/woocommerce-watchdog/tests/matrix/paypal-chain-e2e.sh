#!/bin/sh
# One run of the whole PayPal money chain (ADR 0022): WooCommerce + WooCommerce PayPal Payments in sandbox (shop app)
# + the Watchdog plugin paired with a local backend that has the store's PayPal connected with the Watchdog app.
# Order paid by the sandbox buyer through the PayPal popup → partial refund through WooCommerce → plugin events
# delivered → backend PayPal sync (Transaction Search delta and the capture hint a webhook would give) → automatic
# links → reconciliation. Credentials come from apps/backend/.env and are never printed.
set -eu

cd "$(dirname "$0")"
DOCKER_APP_BIN=/Applications/Docker.app/Contents/Resources/bin
[ -d "$DOCKER_APP_BIN" ] && PATH="$DOCKER_APP_BIN:$PATH"
BACKEND_DIR="$(cd ../../../../apps/backend && pwd)"
HELPER="$(pwd)/backend-e2e.php"
PHP="${PHP:-/opt/homebrew/opt/php@8.4/bin/php}"
PORT="${BW_E2E_PORT:-8768}"
TARGET="${1:-latest}"
ENV_FILE="$BACKEND_DIR/.env"
value() { grep "^$1=" "$ENV_FILE" | cut -d= -f2-; }
for key in WATCHDOG_DEV_PAYPAL_CLIENT_ID WATCHDOG_DEV_PAYPAL_CLIENT_SECRET WATCHDOG_DEV_PAYPAL_SHOP_CLIENT_ID WATCHDOG_DEV_PAYPAL_SHOP_CLIENT_SECRET WATCHDOG_DEV_PAYPAL_BUYER_EMAIL WATCHDOG_DEV_PAYPAL_BUYER_PASSWORD; do
    [ -n "$(value "$key")" ] || { echo "sandbox credentials missing"; exit 2; }
done
mkdir -p .cache
SERVE_LOG="$(pwd)/.cache/paypal-chain-serve.log"
SHOP_HOST="shop-${TARGET}.example.com"

backend() { (cd "$BACKEND_DIR" && env DB_HOST=127.0.0.1 CACHE_STORE=database "$@" "$PHP" artisan tinker --execute="require '${HELPER}';" 2>/dev/null) | sed -n 's/^BW_E2E_JSON=//p'; }
json() { python3 -c "import json,sys; print(json.loads(sys.argv[1])$2)" "$1"; }

(cd "$BACKEND_DIR" && DB_HOST=127.0.0.1 CACHE_STORE=database WATCHDOG_RATE_PAIRING_PER_HOUR=10000 "$PHP" artisan serve --host=0.0.0.0 --port="$PORT" > "$SERVE_LOG" 2>&1) &
trap 'pkill -f "artisan serve --host=0.0.0.0 --port=${PORT}" 2>/dev/null || true' EXIT
sleep 2

BW_MATRIX_KEEP=1 BW_TEST_FILTER=install ./run.sh "$TARGET" >/dev/null 2>&1 || { echo "matrix setup failed"; exit 1; }
DC="docker compose -p bwmatrix-${TARGET} -f docker-compose.yml exec -T -u www-data"
WP="php /bw-cache/wp-cli.phar --path=/var/www/html"
PLUGIN=/var/www/html/wp-content/plugins/business-watchdog/tests/matrix

$DC wordpress $WP plugin is-installed woocommerce-paypal-payments 2>/dev/null || $DC wordpress $WP plugin install woocommerce-paypal-payments >/dev/null 2>&1
$DC wordpress $WP plugin activate woocommerce-paypal-payments >/dev/null 2>&1 || { echo "paypal plugin install failed"; exit 1; }
site() { $DC wordpress $WP option update home "$1" >/dev/null && $DC wordpress $WP option update siteurl "$1" >/dev/null; }
site "https://${SHOP_HOST}"
$DC -e BW_PAYPAL_CLIENT_ID="$(value WATCHDOG_DEV_PAYPAL_SHOP_CLIENT_ID)" -e BW_PAYPAL_CLIENT_SECRET="$(value WATCHDOG_DEV_PAYPAL_SHOP_CLIENT_SECRET)" -e BW_PAYPAL_AUTHORIZE=0 -e BW_PAYPAL_CURRENCY=USD -e BW_PAYPAL_INVOICE_PREFIX="bw-chain-$(date +%s)-" \
    wordpress $WP eval-file $PLUGIN/paypal-spike-configure.php
$DC wordpress $WP transient delete --all >/dev/null 2>&1 || true

setup=$(backend BW_E2E_ACTION=paypal-chain-setup BW_E2E_BASE_URL="https://${SHOP_HOST}")
[ -n "$setup" ] || { echo "backend setup failed (PayPal connect)"; exit 1; }
store_id=$(json "$setup" "['store_id']")
echo "backend store ready, PayPal connected in $(json "$setup" "['paypal_mode']") mode"
$DC wordpress $WP business-watchdog pair --endpoint="http://host.docker.internal:${PORT}" --code="$(json "$setup" "['pairing_code']")" >/dev/null || { echo "pairing failed"; backend BW_E2E_ACTION=paypal-chain-cleanup BW_E2E_STORE_ID="$store_id" >/dev/null; exit 1; }
echo "plugin paired"
site "http://${SHOP_HOST}"

shop=$($DC wordpress $WP eval-file $PLUGIN/checkout-setup.php | sed -n 's/^BW_SETUP=//p')
WP_IP=$(docker inspect -f "{{(index .NetworkSettings.Networks \"bwmatrix-${TARGET}_default\").IPAddress}}" "bwmatrix-${TARGET}-wordpress-1")
OUT="$(pwd)/.cache/paypal-chain"
mkdir -p "$OUT" && chmod 777 "$OUT"
docker build -q -t bw-browser-worker:smoke ../../../../apps/browser-worker >/dev/null
order_id=$(docker run --rm --network "bwmatrix-${TARGET}_default" --add-host "${SHOP_HOST}:${WP_IP}" -v "$(pwd)/paypal-spike-checkout.mjs:/app/paypal-spike-checkout.mjs:ro" -v "$OUT:/out" \
    -e BW_ORIGIN="http://${SHOP_HOST}" -e BW_PRODUCT_ID="$(json "$shop" "['product_id']")" -e BW_CHECKOUT_PAGE_ID="$(json "$shop" "['checkout_page_id']")" \
    -e BUYER_EMAIL="$(value WATCHDOG_DEV_PAYPAL_BUYER_EMAIL)" -e BUYER_PASSWORD="$(value WATCHDOG_DEV_PAYPAL_BUYER_PASSWORD)" \
    bw-browser-worker:smoke node paypal-spike-checkout.mjs | tee "$OUT/checkout.log" | sed -n 's/^BW_PAYPAL_ORDER=//p')
[ -n "$order_id" ] || { echo "checkout failed, see .cache/paypal-chain"; backend BW_E2E_ACTION=paypal-chain-cleanup BW_E2E_STORE_ID="$store_id" >/dev/null; exit 1; }
echo "checkout: order ${order_id}"
$DC -e BW_ORDER_ID="$order_id" -e BW_SPIKE_REFUND=1 wordpress $WP eval-file $PLUGIN/paypal-spike-inspect.php 2>/dev/null | grep -E '^(ORDER|REFUND|refund error) ' || true
$DC wordpress $WP business-watchdog deliver
check=$(backend BW_E2E_ACTION=paypal-chain-check BW_E2E_STORE_ID="$store_id" BW_E2E_ORDER_ID="$order_id")
backend BW_E2E_ACTION=paypal-chain-cleanup BW_E2E_STORE_ID="$store_id" >/dev/null
python3 - "$check" <<'PY'
import json, sys
d = json.loads(sys.argv[1])
for key in ['delta_sync', 'search_refreshed_at', 'capture_hint_sync', 'inbox', 'order', 'provider_payments', 'store_refunds', 'capture_links', 'refund_links', 'findings']:
    print(f"  {key}: {d[key]}")
ok = (d['order'] and d['order']['mode'] == 'test' and d['order']['gateway'] == 'ppcp-gateway'
      and d['capture_links'] == ['exact_reference:1000'] and d['refund_links'] == ['unique_amount:300']
      and d['findings'] and all(f.endswith(':ok') for f in d['findings']))
print('RESULT', 'PASS' if ok else 'FAIL')
sys.exit(0 if ok else 1)
PY
