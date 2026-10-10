#!/bin/sh
# Compatibility spike (ADR 0020): WooCommerce + the official Stripe gateway in test mode. Keys are read from
# apps/backend/.env and only passed to the test WordPress container; nothing is printed.
set -eu

cd "$(dirname "$0")"
DOCKER_APP_BIN=/Applications/Docker.app/Contents/Resources/bin
[ -d "$DOCKER_APP_BIN" ] && PATH="$DOCKER_APP_BIN:$PATH"
ENV_FILE="../../../../apps/backend/.env"
BW_STRIPE_PK=$(grep '^WATCHDOG_DEV_STRIPE_PUBLISHABLE_KEY=' "$ENV_FILE" | cut -d= -f2-)
BW_STRIPE_SK=$(grep '^WATCHDOG_DEV_STRIPE_SECRET_KEY=' "$ENV_FILE" | cut -d= -f2-)
case "$BW_STRIPE_PK$BW_STRIPE_SK" in pk_test_*sk_test_*) ;; *) echo "test keys missing"; exit 2 ;; esac
TARGET="${1:-latest}"

BW_MATRIX_KEEP=1 BW_TEST_FILTER=install ./run.sh "$TARGET" >/dev/null 2>&1 || { echo "matrix setup failed"; exit 1; }
W="docker compose -p bwmatrix-${TARGET} -f docker-compose.yml exec -T -u www-data"
WP="php /bw-cache/wp-cli.phar --path=/var/www/html"

$W wordpress $WP plugin install woocommerce-gateway-stripe --activate >/dev/null 2>&1 || { echo "stripe gateway install failed"; exit 1; }
$W -e BW_STRIPE_PK="$BW_STRIPE_PK" -e BW_STRIPE_SK="$BW_STRIPE_SK" wordpress $WP eval-file /var/www/html/wp-content/plugins/business-watchdog/tests/matrix/stripe-spike-configure.php
echo "gateway plugin version: $($W wordpress $WP plugin get woocommerce-gateway-stripe --field=version)"
setup=$($W wordpress $WP eval-file /var/www/html/wp-content/plugins/business-watchdog/tests/matrix/checkout-setup.php | sed -n 's/^BW_SETUP=//p')
product=$(echo "$setup" | python3 -c "import json,sys; print(json.load(sys.stdin)['product_id'])")
checkout=$(echo "$setup" | python3 -c "import json,sys; print(json.load(sys.stdin)['checkout_page_id'])")
$W -e BW_PRODUCT_ID="$product" -e BW_CHECKOUT_PAGE_ID="$checkout" -e BW_HOST="shop-${TARGET}.example.test" wordpress php /var/www/html/wp-content/plugins/business-watchdog/tests/matrix/stripe-spike-checkout.php
$W -e BW_SPIKE_REFUND=1 wordpress $WP eval-file /var/www/html/wp-content/plugins/business-watchdog/tests/matrix/stripe-spike-inspect.php
