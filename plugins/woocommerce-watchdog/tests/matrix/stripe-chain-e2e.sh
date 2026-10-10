#!/bin/sh
# One run of the whole money chain (ADR 0020): WooCommerce + the official Stripe gateway in test mode + the Watchdog
# plugin paired with a local backend that has the store's Stripe connected with the owner's restricted key.
# Order paid with a Stripe test payment method → partial refund through WooCommerce → plugin events delivered →
# backend Stripe sync → automatic links → reconciliation. Keys come from apps/backend/.env and are never printed.
set -eu

cd "$(dirname "$0")"
DOCKER_APP_BIN=/Applications/Docker.app/Contents/Resources/bin
[ -d "$DOCKER_APP_BIN" ] && PATH="$DOCKER_APP_BIN:$PATH"
BACKEND_DIR="$(cd ../../../../apps/backend && pwd)"
HELPER="$(pwd)/backend-e2e.php"
PHP="${PHP:-/opt/homebrew/opt/php@8.4/bin/php}"
PORT="${BW_E2E_PORT:-8767}"
TARGET="${1:-latest}"
ENV_FILE="$BACKEND_DIR/.env"
BW_STRIPE_PK=$(grep '^WATCHDOG_DEV_STRIPE_PUBLISHABLE_KEY=' "$ENV_FILE" | cut -d= -f2-)
BW_STRIPE_SK=$(grep '^WATCHDOG_DEV_STRIPE_SECRET_KEY=' "$ENV_FILE" | cut -d= -f2-)
case "$BW_STRIPE_PK$BW_STRIPE_SK" in pk_test_*sk_test_*) ;; *) echo "test keys missing"; exit 2 ;; esac
mkdir -p .cache
SERVE_LOG="$(pwd)/.cache/stripe-chain-serve.log"

backend() { (cd "$BACKEND_DIR" && env DB_HOST=127.0.0.1 "$@" "$PHP" artisan tinker --execute="require '${HELPER}';" 2>/dev/null) | sed -n 's/^BW_E2E_JSON=//p'; }
json() { python3 -c "import json,sys; print(json.loads(sys.argv[1])$2)" "$1"; }

(cd "$BACKEND_DIR" && DB_HOST=127.0.0.1 CACHE_STORE=database WATCHDOG_RATE_PAIRING_PER_HOUR=10000 "$PHP" artisan serve --host=0.0.0.0 --port="$PORT" > "$SERVE_LOG" 2>&1) &
trap 'pkill -f "artisan serve --host=0.0.0.0 --port=${PORT}" 2>/dev/null || true' EXIT
sleep 2

BW_MATRIX_KEEP=1 BW_TEST_FILTER=install ./run.sh "$TARGET" >/dev/null 2>&1 || { echo "matrix setup failed"; exit 1; }
DC="docker compose -p bwmatrix-${TARGET} -f docker-compose.yml exec -T -u www-data"
WP="php /bw-cache/wp-cli.phar --path=/var/www/html"
PLUGIN=/var/www/html/wp-content/plugins/business-watchdog/tests/matrix

$DC wordpress $WP plugin install woocommerce-gateway-stripe --activate >/dev/null 2>&1 || { echo "stripe gateway install failed"; exit 1; }
$DC -e BW_STRIPE_PK="$BW_STRIPE_PK" -e BW_STRIPE_SK="$BW_STRIPE_SK" wordpress $WP eval-file $PLUGIN/stripe-spike-configure.php

setup=$(backend BW_E2E_ACTION=stripe-chain-setup BW_E2E_BASE_URL="https://shop-${TARGET}.example.test")
store_id=$(json "$setup" "['store_id']")
echo "backend store ready, Stripe connected in $(json "$setup" "['stripe_mode']") mode"
$DC wordpress $WP business-watchdog pair --endpoint="http://host.docker.internal:${PORT}" --code="$(json "$setup" "['pairing_code']")" >/dev/null && echo "plugin paired"

shop=$($DC wordpress $WP eval-file $PLUGIN/checkout-setup.php | sed -n 's/^BW_SETUP=//p')
checkout=$($DC -e BW_PRODUCT_ID="$(json "$shop" "['product_id']")" -e BW_CHECKOUT_PAGE_ID="$(json "$shop" "['checkout_page_id']")" -e BW_HOST="shop-${TARGET}.example.test" wordpress php $PLUGIN/stripe-spike-checkout.php | sed -n 's/^BW_SPIKE=//p')
order_id=$(json "$checkout" "['order_id']")
echo "checkout: $(json "$checkout" "['result']") order ${order_id}"
$DC -e BW_SPIKE_REFUND=1 wordpress $WP eval-file $PLUGIN/stripe-spike-inspect.php 2>/dev/null | grep -E '^(ORDER|REFUND) ' || true
$DC wordpress $WP business-watchdog deliver
check=$(backend BW_E2E_ACTION=stripe-chain-check BW_E2E_STORE_ID="$store_id" BW_E2E_ORDER_ID="$order_id")
backend BW_E2E_ACTION=stripe-chain-cleanup BW_E2E_STORE_ID="$store_id" >/dev/null
python3 - "$check" <<'PY'
import json, sys
d = json.loads(sys.argv[1])
for key in ['sync', 'inbox', 'order', 'store_refunds', 'capture_links', 'refund_links', 'findings']:
    print(f"  {key}: {d[key]}")
ok = (d['order'] and d['order']['mode'] == 'test' and d['capture_links'] == ['exact_reference:1000']
      and d['refund_links'] == ['exact_reference:300'] and d['findings']
      and all(f.endswith(':ok') for f in d['findings']))
print('RESULT', 'PASS' if ok else 'FAIL')
sys.exit(0 if ok else 1)
PY
