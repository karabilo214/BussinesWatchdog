#!/bin/sh
set -eu

cd "$(dirname "$0")"

DOCKER_APP_BIN=/Applications/Docker.app/Contents/Resources/bin
[ -d "$DOCKER_APP_BIN" ] && PATH="$DOCKER_APP_BIN:$PATH"

WP_CLI_VERSION=2.11.0
TARGETS="${*:-$(cut -d= -f1 targets.env | tr '\n' ' ')}"
KEEP="${BW_MATRIX_KEEP:-0}"
SUMMARY=""
STATUS=0

mkdir -p .cache
[ -f .cache/wp-cli.phar ] || curl -fsSL -o .cache/wp-cli.phar "https://github.com/wp-cli/wp-cli/releases/download/v${WP_CLI_VERSION}/wp-cli-${WP_CLI_VERSION}.phar"

for target in $TARGETS; do
    line=$(grep "^${target}=" targets.env | cut -d= -f2-)
    [ -n "$line" ] || { echo "Unknown target: $target"; exit 2; }
    WP_IMAGE=$(echo "$line" | cut -d'|' -f1)
    WC_VERSION=$(echo "$line" | cut -d'|' -f2)
    STORAGE=$(echo "$line" | cut -d'|' -f3)
    PROJECT="bwmatrix-${target}"
    [ -f ".cache/woocommerce.${WC_VERSION}.zip" ] || curl -fsSL -o ".cache/woocommerce.${WC_VERSION}.zip" "https://downloads.wordpress.org/plugin/woocommerce.${WC_VERSION}.zip"

    export WP_IMAGE
    compose() { docker compose -p "$PROJECT" -f docker-compose.yml "$@"; }
    wp() { compose exec -T -u www-data -e BW_EXPECT_WC_VERSION="$WC_VERSION" -e BW_EXPECT_ORDER_STORAGE="$STORAGE" -e BW_TEST_FILTER="${BW_TEST_FILTER:-}" -e BW_E2E_ENDPOINT="${BW_E2E_ENDPOINT:-}" -e BW_E2E_SETUP="${BW_E2E_SETUP:-}" wordpress php /bw-cache/wp-cli.phar --path=/var/www/html "$@"; }

    echo "=== ${target}: ${WP_IMAGE} + WooCommerce ${WC_VERSION} (${STORAGE})"
    compose down -v >/dev/null 2>&1 || true
    compose up -d --wait >/dev/null

    i=0
    until compose exec -T wordpress test -f /var/www/html/wp-config.php 2>/dev/null; do
        i=$((i + 1)); [ $i -gt 60 ] && { echo "wordpress did not start"; exit 1; }; sleep 1
    done

    wp core install --url="https://shop-${target}.example.test" --title="BW ${target}" --admin_user=admin --admin_password=admin --admin_email=admin@example.test --skip-email >/dev/null
    wp plugin install "/bw-cache/woocommerce.${WC_VERSION}.zip" --activate >/dev/null
    wp option update woocommerce_currency EUR >/dev/null
    wp option update woocommerce_coming_soon no >/dev/null 2>&1 || true

    if [ "$STORAGE" = "hpos" ]; then
        wp wc hpos enable >/dev/null 2>&1 || wp eval-file /var/www/html/wp-content/plugins/business-watchdog/tests/matrix/enable-hpos.php >/dev/null
    else
        wp wc hpos disable >/dev/null 2>&1 || true
    fi

    wp plugin activate business-watchdog >/dev/null

    if wp eval-file /var/www/html/wp-content/plugins/business-watchdog/tests/integration/run.php > ".cache/result-${target}.json" 2>".cache/result-${target}.err"; then
        SUMMARY="${SUMMARY}PASS ${target}\n"
    else
        SUMMARY="${SUMMARY}FAIL ${target}\n"
        STATUS=1
    fi

    python3 -c "import json,sys; d=json.load(open('.cache/result-${target}.json')); print('  passed', d['passed'], 'failed', d['failed']); [print('  FAIL', r['test'], '-', r['error']) for r in d['results'] if not r['ok']]" 2>/dev/null || { echo "  no result"; tail -5 ".cache/result-${target}.err"; }

    [ "$KEEP" = "1" ] || compose down -v >/dev/null 2>&1 || true
done

printf "\n%b" "$SUMMARY"
exit $STATUS
