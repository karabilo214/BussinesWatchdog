#!/bin/sh
# Compatibility spike (ADR 0022): WooCommerce + WooCommerce PayPal Payments in sandbox. Credentials are read from
# apps/backend/.env and passed only to the test WordPress container and the browser container; nothing is printed.
set -eu

cd "$(dirname "$0")"
DOCKER_APP_BIN=/Applications/Docker.app/Contents/Resources/bin
[ -d "$DOCKER_APP_BIN" ] && PATH="$DOCKER_APP_BIN:$PATH"
ENV_FILE="../../../../apps/backend/.env"
value() { grep "^$1=" "$ENV_FILE" | cut -d= -f2-; }
TARGET="${1:-latest}"

if [ "${BW_PAYPAL_REUSE:-0}" != "1" ]; then
    BW_MATRIX_KEEP=1 BW_TEST_FILTER=install ./run.sh "$TARGET" >/dev/null 2>&1 || { echo "matrix setup failed"; exit 1; }
fi
W="docker compose -p bwmatrix-${TARGET} -f docker-compose.yml exec -T -u www-data"
WP="php /bw-cache/wp-cli.phar --path=/var/www/html"

$W wordpress $WP plugin is-installed woocommerce-paypal-payments 2>/dev/null || $W wordpress $WP plugin install woocommerce-paypal-payments >/dev/null 2>&1
$W wordpress $WP plugin activate woocommerce-paypal-payments >/dev/null 2>&1 || { echo "paypal plugin install failed"; exit 1; }
$W -e BW_PAYPAL_CLIENT_ID="$(value WATCHDOG_DEV_PAYPAL_SHOP_CLIENT_ID)" -e BW_PAYPAL_CLIENT_SECRET="$(value WATCHDOG_DEV_PAYPAL_SHOP_CLIENT_SECRET)" \
    -e BW_PAYPAL_AUTHORIZE="${BW_PAYPAL_AUTHORIZE:-0}" -e BW_PAYPAL_CURRENCY="${BW_PAYPAL_CURRENCY:-USD}" \
    wordpress $WP eval-file /var/www/html/wp-content/plugins/business-watchdog/tests/matrix/paypal-spike-configure.php
echo "paypal plugin version: $($W wordpress $WP plugin get woocommerce-paypal-payments --field=version)"

SHOP_HOST="${BW_PAYPAL_SHOP_HOST:-shop-${TARGET}.example.com}"
$W wordpress $WP option update home "http://${SHOP_HOST}" >/dev/null
$W wordpress $WP option update siteurl "http://${SHOP_HOST}" >/dev/null
$W wordpress $WP transient delete --all >/dev/null 2>&1 || true
WP_IP=$(docker inspect -f "{{(index .NetworkSettings.Networks \"bwmatrix-${TARGET}_default\").IPAddress}}" "bwmatrix-${TARGET}-wordpress-1")
setup=$($W wordpress $WP eval-file /var/www/html/wp-content/plugins/business-watchdog/tests/matrix/checkout-setup.php | sed -n 's/^BW_SETUP=//p')
product=$(echo "$setup" | python3 -c "import json,sys; print(json.load(sys.stdin)['product_id'])")
checkout=$(echo "$setup" | python3 -c "import json,sys; print(json.load(sys.stdin)['checkout_page_id'])")
OUT="$(pwd)/.cache/paypal-spike"
mkdir -p "$OUT" && chmod 777 "$OUT"
docker build -q -t bw-browser-worker:smoke ../../../../apps/browser-worker >/dev/null
docker run --rm --network "bwmatrix-${TARGET}_default" --add-host "${SHOP_HOST}:${WP_IP}" -v "$(pwd)/paypal-spike-checkout.mjs:/app/paypal-spike-checkout.mjs:ro" -v "$OUT:/out" \
    -e BW_ORIGIN="http://${SHOP_HOST}" -e BW_PRODUCT_ID="$product" -e BW_CHECKOUT_PAGE_ID="$checkout" \
    -e BUYER_EMAIL="$(value WATCHDOG_DEV_PAYPAL_BUYER_EMAIL)" -e BUYER_PASSWORD="$(value WATCHDOG_DEV_PAYPAL_BUYER_PASSWORD)" \
    bw-browser-worker:smoke node paypal-spike-checkout.mjs | tee "$OUT/checkout.log"
