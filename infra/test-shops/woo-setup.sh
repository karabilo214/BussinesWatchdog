#!/bin/sh
# Installs and configures the WooCommerce test store in bw-test-woo (run on the server from infra/test-shops after
# `docker compose up -d woo-db woo`): WordPress with the admin password from test-shops.env, WooCommerce 11.2.0, Storefront,
# Stripe Gateway 11.0.1 and PayPal Payments 4.1.3, then woo-setup.php. The Stripe test keys and the PayPal sandbox shop
# app come as a PHP prelude on stdin from the operator's machine (see docs/ops/test-shops.md); they are not stored here.
set -eu
. /opt/business-watchdog/test-shops.env
W="docker exec -u www-data bw-test-woo"
WP="php /tmp/wp-cli.phar"
$W sh -c "test -f /tmp/wp-cli.phar || curl -sSLo /tmp/wp-cli.phar https://github.com/wp-cli/wp-cli/releases/download/v2.12.0/wp-cli-2.12.0.phar"
$W $WP core is-installed 2>/dev/null || $W $WP core install --url="https://woo-test.$SITE_DOMAIN" --title="BW Test Shop (WooCommerce)" \
    --admin_user=bwadmin --admin_password="$WOO_ADMIN_PASSWORD" --admin_email="support@$SITE_DOMAIN" --skip-email
$W $WP plugin install woocommerce --version=11.2.0 --activate
$W $WP theme install storefront --activate
$W $WP plugin install woocommerce-gateway-stripe --version=11.0.1 --activate
$W $WP plugin install woocommerce-paypal-payments --version=4.1.3 --activate
{ cat; tail -n +2 "$(dirname "$0")/woo-setup.php"; } | docker exec -i -u www-data bw-test-woo sh -c 'umask 077; cat > /tmp/bw-setup.php && php /tmp/wp-cli.phar eval-file /tmp/bw-setup.php; rm -f /tmp/bw-setup.php'
