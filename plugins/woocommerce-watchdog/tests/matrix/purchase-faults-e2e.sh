#!/bin/sh
# Local purchase robot and storefront fault matrix. No external payment provider is used.
set -eu

cd "$(dirname "$0")"
DOCKER_APP_BIN=/Applications/Docker.app/Contents/Resources/bin
[ -d "$DOCKER_APP_BIN" ] && PATH="$DOCKER_APP_BIN:$PATH"
TARGET="${1:-latest}"
if [ "${BW_MATRIX_REUSE:-0}" != '1' ]; then
    BW_MATRIX_KEEP=1 BW_TEST_FILTER=install ./run.sh "$TARGET" >/dev/null
fi
DC="docker compose -p bwmatrix-${TARGET} -f docker-compose.yml exec -T -u www-data"
WP="php /bw-cache/wp-cli.phar --path=/var/www/html"
PLUGIN=/var/www/html/wp-content/plugins/business-watchdog/tests/matrix

$DC wordpress $WP option update home http://wordpress >/dev/null
$DC wordpress $WP option update siteurl http://wordpress >/dev/null
setup=$($DC wordpress $WP eval-file "$PLUGIN/checkout-setup.php" | sed -n 's/^BW_SETUP=//p')
[ -n "$setup" ] || { echo 'WooCommerce setup failed'; exit 1; }
field() { python3 -c 'import json,sys; print(json.loads(sys.argv[1])[sys.argv[2]])' "$setup" "$1"; }
cart_page_id=$($DC wordpress $WP post create --post_type=page --post_status=publish --post_title='Classic cart' --post_content='[woocommerce_cart]' --porcelain)
$DC wordpress $WP option update woocommerce_cart_page_id "$cart_page_id" >/dev/null

docker build -q -t bw-browser-worker:purchase-lab ../../../../apps/browser-worker >/dev/null

count_real_orders='echo count(wc_get_orders(["type" => "shop_order", "status" => ["pending", "processing", "on-hold", "completed", "failed", "cancelled", "refunded"], "limit" => -1]));'
before=$($DC wordpress $WP eval "$count_real_orders")
for checkout_page_id in "$(field checkout_page_id)" "$(field blocks_checkout_page_id)"; do
    [ "$checkout_page_id" = 0 ] && continue
    docker run --rm --network "bwmatrix-${TARGET}_default" \
        --read-only --tmpfs /tmp --cap-drop ALL --security-opt no-new-privileges \
        -v "$(pwd)/scheduled-check.mjs:/app/scheduled-check.mjs:ro" \
        -v "$(pwd)/../../../../apps/browser-worker/src:/app/src:ro" \
        -e BW_SHOP_ORIGIN=http://wordpress -e BW_PRODUCT_ID="$(field product_id)" \
        -e BW_CART_PAGE_ID="$cart_page_id" -e BW_CHECKOUT_PAGE_ID="$checkout_page_id" \
        bw-browser-worker:purchase-lab node /app/scheduled-check.mjs
done
after=$($DC wordpress $WP eval "$count_real_orders")
[ "$before" = "$after" ] || { echo 'scheduled check created an order'; exit 1; }

for mode in ${BW_PURCHASE_MODES:-healthy product_button cart_button cart_script cart_price cannot_buy gateway_no_response}; do
    echo "${mode}:"
    docker run --rm --network "bwmatrix-${TARGET}_default" \
        --read-only --tmpfs /tmp --cap-drop ALL --security-opt no-new-privileges \
        -v "$(pwd)/purchase-robot.mjs:/app/purchase-robot.mjs:ro" \
        -e BW_PURCHASE_LAB=1 -e BW_SHOP_ORIGIN=http://wordpress \
        -e BW_PRODUCT_ID="$(field product_id)" -e BW_CART_PAGE_ID="$cart_page_id" -e BW_CHECKOUT_PAGE_ID="$(field checkout_page_id)" \
        -e BW_FAULT_MODE="$mode" bw-browser-worker:purchase-lab node /app/purchase-robot.mjs
done
