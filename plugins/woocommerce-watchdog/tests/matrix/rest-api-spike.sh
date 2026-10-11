#!/bin/sh
# WooCommerce REST API spike (ADR 0024): a Read-only API key on a matrix store without the Watchdog plugin; what the
# API returns for money, refunds, trash and deletion, paging by modification time, payload size, and that every write
# is refused. Usage: rest-api-spike.sh [targets...]
set -eu

cd "$(dirname "$0")"
DOCKER_APP_BIN=/Applications/Docker.app/Contents/Resources/bin
[ -d "$DOCKER_APP_BIN" ] && PATH="$DOCKER_APP_BIN:$PATH"

for TARGET in ${*:-floor latest}; do
    BW_MATRIX_KEEP=1 BW_TEST_FILTER=install ./run.sh "$TARGET" >/dev/null 2>&1 || { echo "matrix setup failed"; exit 1; }
    W="docker compose -p bwmatrix-${TARGET} -f docker-compose.yml exec -T -u www-data"
    WP="php /bw-cache/wp-cli.phar --path=/var/www/html"
    M=/var/www/html/wp-content/plugins/business-watchdog/tests/matrix
    $W wordpress $WP plugin deactivate business-watchdog >/dev/null
    seed=$($W -e BW_SPIKE_BULK="${BW_SPIKE_BULK:-250}" wordpress $WP eval-file $M/rest-api-spike-seed.php | sed -n 's/^BW_SPIKE=//p')
    echo "=== ${TARGET} ($(grep "^${TARGET}=" targets.env | cut -d= -f2-))"
    echo "--- plain permalinks, ?rest_route="
    $W -e BW_SPIKE="$seed" -e BW_HOST="shop-${TARGET}.example.test" wordpress php $M/rest-api-spike-probe.php | grep -E '^(no auth|order money|list status|basic auth)'
    $W wordpress $WP rewrite structure '/%postname%/' --hard >/dev/null 2>&1
    echo "--- pretty permalinks, /wp-json/"
    $W -e BW_SPIKE="$seed" -e BW_HOST="shop-${TARGET}.example.test" -e BW_REST_MODE=pretty wordpress php $M/rest-api-spike-probe.php
    paypal=$(python3 -c "import json,sys; print(json.loads(sys.argv[1])['paypal'])" "$seed")
    $W -e BW_ORDER="$paypal" wordpress $WP eval-file $M/rest-api-spike-late.php
    [ "${BW_MATRIX_KEEP:-0}" = "1" ] || WP_IMAGE=unused docker compose -p "bwmatrix-${TARGET}" -f docker-compose.yml down -v >/dev/null 2>&1 || true
done
