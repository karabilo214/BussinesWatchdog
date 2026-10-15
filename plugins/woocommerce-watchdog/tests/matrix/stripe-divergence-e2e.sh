#!/bin/sh
# Real Stripe test-mode captures and a controlled disagreement with WooCommerce.
# Backend PHP runs in Docker; the official gateway receives test keys only.
set -eu

cd "$(dirname "$0")"
DOCKER_APP_BIN=/Applications/Docker.app/Contents/Resources/bin
[ -d "$DOCKER_APP_BIN" ] && PATH="$DOCKER_APP_BIN:$PATH"
ROOT="$(cd ../../../.. && pwd)"
HELPER="$(pwd)/backend-e2e.php"
ENV_FILE="$ROOT/apps/backend/.env"
value() { sed -n "s/^$1=//p" "$ENV_FILE" | head -1; }
PK=$(value WATCHDOG_DEV_STRIPE_PUBLISHABLE_KEY)
SK=$(value WATCHDOG_DEV_STRIPE_SECRET_KEY)
case "$PK$SK" in pk_test_*sk_test_*) ;; *) echo 'Stripe test keys missing'; exit 2 ;; esac
TARGET="${1:-latest}"
BW_MATRIX_KEEP=1 BW_TEST_FILTER=install ./run.sh "$TARGET" >/dev/null
DC="docker compose -p bwmatrix-${TARGET} -f docker-compose.yml exec -T -u www-data"
WP="php /bw-cache/wp-cli.phar --path=/var/www/html"
PLUGIN=/var/www/html/wp-content/plugins/business-watchdog/tests/matrix
json() { python3 -c 'import json,sys; print(json.loads(sys.argv[1])[sys.argv[2]])' "$1" "$2"; }
backend() {
    (cd "$ROOT" && env "$@" docker compose run --rm --no-deps -T \
        -v "$HELPER:/tmp/bw-e2e.php:ro" \
        -e BW_E2E_ACTION -e BW_E2E_BASE_URL -e BW_E2E_STORE_ID -e BW_E2E_ORDER_ID \
        backend-cli php artisan tinker --execute="require '/tmp/bw-e2e.php';" 2>/dev/null) | sed -n 's/^BW_E2E_JSON=//p'
}

$DC wordpress $WP plugin install woocommerce-gateway-stripe --activate >/dev/null 2>&1
$DC -e BW_STRIPE_PK="$PK" -e BW_STRIPE_SK="$SK" wordpress $WP eval-file "$PLUGIN/stripe-spike-configure.php" >/dev/null
setup=$(backend BW_E2E_ACTION=stripe-chain-setup BW_E2E_BASE_URL="https://shop-${TARGET}.example.test")
[ -n "$setup" ] || { echo 'backend Stripe setup failed'; exit 1; }
store_id=$(json "$setup" store_id)
trap 'backend BW_E2E_ACTION=stripe-chain-cleanup BW_E2E_STORE_ID="$store_id" >/dev/null || true' EXIT
$DC wordpress $WP business-watchdog pair --endpoint=http://host.docker.internal:8081 --code="$(json "$setup" pairing_code)" >/dev/null
shop=$($DC wordpress $WP eval-file "$PLUGIN/checkout-setup.php" | sed -n 's/^BW_SETUP=//p')
product_id=$(json "$shop" product_id)
checkout_id=$(json "$shop" checkout_page_id)

purchase() {
    $DC -e BW_PRODUCT_ID="$product_id" -e BW_CHECKOUT_PAGE_ID="$checkout_id" -e BW_HOST="shop-${TARGET}.example.test" -e BW_PAYMENT_METHOD="$1" \
        wordpress php "$PLUGIN/stripe-spike-checkout.php" | sed -n 's/^BW_SPIKE=//p'
}

paid=$(purchase pm_card_visa)
[ "$(json "$paid" result)" = success ] || { echo 'Stripe test capture failed'; exit 1; }
paid_order=$(json "$paid" order_id)
$DC -e BW_ORDER_ID="$paid_order" wordpress $WP eval '$o=wc_get_order((int)getenv("BW_ORDER_ID")); $o->set_status("pending"); $o->set_date_paid(null); $o->set_transaction_id(""); $o->save(); echo "store_unpaid\n";' >/dev/null
$DC wordpress $WP business-watchdog deliver >/dev/null
paid_check=$(backend BW_E2E_ACTION=stripe-chain-check BW_E2E_STORE_ID="$store_id" BW_E2E_ORDER_ID="$paid_order")
python3 - "$paid_check" <<'PY'
import json,sys
d=json.loads(sys.argv[1])
assert d['capture_links'] and 'MONEY_CAPTURE_NOT_RECORDED:pending' in d['findings'], d
assert 'MONEY_CAPTURE_AMOUNT:ok' in d['findings'], d
print('provider_paid_store_unpaid', {'capture_links':d['capture_links'], 'findings':d['findings'], 'order':d['order']})
PY

declined=$(purchase pm_card_chargeDeclined)
echo "declined checkout result: $(json "$declined" result)"
declined_order=$($DC wordpress $WP eval '$ids=wc_get_orders(["limit"=>1,"orderby"=>"date","order"=>"DESC","return"=>"ids"]); echo (int)($ids[0]??0);')
[ "$declined_order" -gt "$paid_order" ] || { echo 'declined test order missing'; exit 1; }
$DC -e BW_ORDER_ID="$declined_order" wordpress $WP eval '$o=wc_get_order((int)getenv("BW_ORDER_ID")); $o->set_status("processing"); $o->set_date_paid(time()); $o->save(); echo "store_paid\n";' >/dev/null
$DC wordpress $WP business-watchdog deliver >/dev/null
unpaid_check=$(backend BW_E2E_ACTION=stripe-chain-check BW_E2E_STORE_ID="$store_id" BW_E2E_ORDER_ID="$declined_order")
python3 - "$unpaid_check" <<'PY'
import json,sys
d=json.loads(sys.argv[1])
assert not d['capture_links'] and 'MONEY_CAPTURE_MISSING:pending' in d['findings'], d
print('store_paid_provider_unpaid', {'capture_links':d['capture_links'], 'findings':d['findings'], 'order':d['order']})
PY
