#!/bin/sh
set -eu

cd "$(dirname "$0")"

DOCKER_APP_BIN=/Applications/Docker.app/Contents/Resources/bin
[ -d "$DOCKER_APP_BIN" ] && PATH="$DOCKER_APP_BIN:$PATH"

BACKEND_DIR="$(cd ../../../../apps/backend && pwd)"
WORKER_DIR="$(cd ../../../../apps/browser-worker && pwd)"
HELPER="$(pwd)/backend-e2e.php"
SERVE_LOG="$(pwd)/.cache/browser-serve.log"
mkdir -p .cache
PHP="${PHP:-/opt/homebrew/opt/php@8.4/bin/php}"
PORT="${BW_E2E_PORT:-8766}"
TARGETS="${*:-$(cut -d= -f1 targets.env | tr '\n' ' ')}"
STATUS=0
ORIGIN="http://wordpress"

backend() {
    (cd "$BACKEND_DIR" && env DB_HOST=127.0.0.1 "$@" "$PHP" artisan tinker --execute="require '${HELPER}';" 2>/dev/null) | sed -n 's/^BW_E2E_JSON=//p'
}

json() { python3 -c "import json,sys; print(json.loads(sys.argv[1])$2)" "$1"; }

docker build -q -t bw-browser-worker:e2e "$WORKER_DIR" >/dev/null

(cd "$BACKEND_DIR" && DB_HOST=127.0.0.1 CACHE_STORE=database "$PHP" artisan serve --host=0.0.0.0 --port="$PORT" > "$SERVE_LOG" 2>&1) &
SERVE_PID=$!
trap 'kill $SERVE_PID 2>/dev/null; pkill -f "artisan serve --host=0.0.0.0 --port=${PORT}" 2>/dev/null || true' EXIT
sleep 2

for target in $TARGETS; do
    BW_MATRIX_KEEP=1 BW_TEST_FILTER=install ./run.sh "$target" >/dev/null 2>&1 || { echo "FAIL $target (setup)"; STATUS=1; continue; }
    W="docker compose -p bwmatrix-${target} -f docker-compose.yml exec -T -u www-data wordpress php /bw-cache/wp-cli.phar --path=/var/www/html"
    result="ok"
    : > ".cache/browser-worker-${target}.log"

    setup=$($W eval-file /var/www/html/wp-content/plugins/business-watchdog/tests/matrix/checkout-setup.php 2>/dev/null | sed -n 's/^BW_SETUP=//p')
    $W option update home "$ORIGIN" >/dev/null
    $W option update siteurl "$ORIGIN" >/dev/null
    product="${ORIGIN}/?p=$(json "$setup" "['product_id']")"
    cart="${ORIGIN}/?page_id=$(json "$setup" "['cart_page_id']")"
    classic="${ORIGIN}/?page_id=$(json "$setup" "['checkout_page_id']")"
    blocks_id=$(json "$setup" "['blocks_checkout_page_id']")
    count_orders='global $wpdb; $t = $wpdb->prefix . ((get_option("woocommerce_custom_orders_table_enabled") === "yes") ? "wc_orders" : "posts"); $col = strpos($t, "wc_orders") ? "status" : "post_status"; $type = strpos($t, "wc_orders") ? "type" : "post_type"; echo (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE {$type} = \"shop_order\" AND {$col} NOT IN (\"wc-checkout-draft\", \"checkout-draft\", \"auto-draft\", \"trash\")") . "/" . (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bw_payment_attempts");'
    before=$($W eval "$count_orders")

    bsetup=$(backend BW_E2E_ACTION=browser-setup BW_E2E_ORIGIN="$ORIGIN" BW_E2E_PRODUCT_URL="$product" BW_E2E_CART_URL="$cart" BW_E2E_CHECKOUT_URL="$classic")
    scenario_id=$(json "$bsetup" "['scenario_id']")
    token=$(json "$bsetup" "['worker_token']")

    run_worker() {
        docker run --rm --network "bwmatrix-${target}_default" --add-host host.docker.internal:host-gateway \
            --read-only --tmpfs /tmp --cap-drop ALL --security-opt no-new-privileges \
            -e BW_API_URL="http://host.docker.internal:${PORT}" -e BW_WORKER_TOKEN="$token" \
            -e BW_WORKER_INSECURE_LOCAL=1 -e NODE_ENV=development -e BW_RUN_ONCE=1 -e BW_WORKER_LOCATION=matrix \
            bw-browser-worker:e2e >> ".cache/browser-worker-${target}.log" 2>&1 || true
    }

    check_run() {
        mode="$1"; checkout_url="$2"; expect="$3"
        run_id=$(json "$(backend BW_E2E_ACTION=browser-run BW_E2E_SCENARIO_ID="$scenario_id" BW_E2E_CHECKOUT_URL="$checkout_url")" "['run_id']")
        run_worker
        state=$(backend BW_E2E_ACTION=browser-status BW_E2E_RUN_ID="$run_id")
        echo "  ${mode}: ${state}"
        verdict=$(python3 - "$state" "$expect" <<'PY'
import json, sys
state = json.loads(sys.argv[1])
expect = sys.argv[2]
attempt = state['attempts'][0] if state['attempts'] else None
if attempt is None:
    print('no_attempt')
elif expect.startswith('passed:'):
    mode = expect.split(':', 1)[1]
    ok = state['run_status'] == 'passed' and attempt['steps'][-1].startswith('payment_form:passed') and any('checkout:passed(' + mode + ')' == s for s in attempt['steps'])
    print('ok' if ok else 'unexpected')
else:
    ok = attempt['status'] == 'failed' and attempt['error_code'] == 'site_failure' and any(s.startswith('payment_form:failed:site_failure') for s in attempt['steps']) and state['run_status'] == 'queued'
    print('ok' if ok else 'unexpected')
PY
)
        [ "$verdict" = "ok" ] || result="${mode}_${verdict}"
    }

    check_run classic "$classic" "passed:classic"

    if [ "$blocks_id" != "0" ]; then
        check_run blocks "${ORIGIN}/?page_id=${blocks_id}" "passed:blocks"
    else
        echo "  blocks: no block checkout page in this WooCommerce version (skipped)"
    fi

    $W option update bw_matrix_decline_gateway no >/dev/null
    $W option update woocommerce_bacs_settings '{"enabled":"no"}' --format=json >/dev/null
    $W option update woocommerce_cod_settings '{"enabled":"no"}' --format=json >/dev/null
    $W option update woocommerce_cheque_settings '{"enabled":"no"}' --format=json >/dev/null
    check_run no-payment-methods "$classic" "site_failure"

    after=$($W eval "$count_orders")
    echo "  orders/attempts before=${before} after=${after}"
    [ "$before" = "$after" ] || result="orders_created(${before}->${after})"

    if [ "$result" = "ok" ]; then echo "PASS ${target} browser-e2e"; else echo "FAIL ${target} browser-e2e: ${result}"; STATUS=1; fi
    docker compose -p "bwmatrix-${target}" -f docker-compose.yml down -v >/dev/null 2>&1 || true
done

exit $STATUS
