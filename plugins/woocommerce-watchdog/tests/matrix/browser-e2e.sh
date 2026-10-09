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

S3_ENV="AWS_ENDPOINT=http://127.0.0.1:${S3_PORT:-9090} AWS_USE_PATH_STYLE_ENDPOINT=true"
ARTIFACT_DIR="$(pwd)/.cache/artifacts"
mkdir -p "$ARTIFACT_DIR"

backend() {
    (cd "$BACKEND_DIR" && env DB_HOST=127.0.0.1 $S3_ENV BW_E2E_ARTIFACT_DIR="$ARTIFACT_DIR" "$@" "$PHP" artisan tinker --execute="require '${HELPER}';" 2>/dev/null) | sed -n 's/^BW_E2E_JSON=//p'
}

json() { python3 -c "import json,sys; print(json.loads(sys.argv[1])$2)" "$1"; }

docker build -q -t bw-browser-worker:e2e "$WORKER_DIR" >/dev/null
(cd ../../../.. && docker compose up -d s3 >/dev/null)

(cd "$BACKEND_DIR" && env DB_HOST=127.0.0.1 CACHE_STORE=database $S3_ENV "$PHP" artisan serve --host=0.0.0.0 --port="$PORT" > "$SERVE_LOG" 2>&1) &
SERVE_PID=$!
trap 'kill $SERVE_PID 2>/dev/null; pkill -f "artisan serve --host=0.0.0.0 --port=${PORT}" 2>/dev/null || true' EXIT
sleep 2

for target in $TARGETS; do
    BW_MATRIX_KEEP=1 BW_TEST_FILTER=install ./run.sh "$target" >/dev/null 2>&1 || { echo "FAIL $target (setup)"; STATUS=1; continue; }
    W="docker compose -p bwmatrix-${target} -f docker-compose.yml exec -T -u www-data wordpress php /bw-cache/wp-cli.phar --path=/var/www/html"
    result="ok"
    : > ".cache/browser-worker-${target}.log"

    setup=$($W eval-file /var/www/html/wp-content/plugins/business-watchdog/tests/matrix/checkout-setup.php 2>/dev/null | sed -n 's/^BW_SETUP=//p')
    product="${ORIGIN}/?p=$(json "$setup" "['product_id']")"
    cart="${ORIGIN}/?page_id=$(json "$setup" "['cart_page_id']")"
    classic="${ORIGIN}/?page_id=$(json "$setup" "['checkout_page_id']")"
    blocks_id=$(json "$setup" "['blocks_checkout_page_id']")
    count_orders='global $wpdb; $t = $wpdb->prefix . ((get_option("woocommerce_custom_orders_table_enabled") === "yes") ? "wc_orders" : "posts"); $col = strpos($t, "wc_orders") ? "status" : "post_status"; $type = strpos($t, "wc_orders") ? "type" : "post_type"; echo (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE {$type} = \"shop_order\" AND {$col} NOT IN (\"wc-checkout-draft\", \"checkout-draft\", \"auto-draft\", \"trash\")") . "/" . (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bw_payment_attempts");'
    before=$($W eval "$count_orders")

    bsetup=$(backend BW_E2E_ACTION=browser-setup BW_E2E_BASE_URL="https://shop-${target}.example.test" BW_E2E_ORIGIN="$ORIGIN" BW_E2E_PRODUCT_URL="$product" BW_E2E_CART_URL="$cart" BW_E2E_CHECKOUT_URL="$classic")
    scenario_id=$(json "$bsetup" "['scenario_id']")
    token=$(json "$bsetup" "['worker_token']")
    $W business-watchdog pair --endpoint="http://host.docker.internal:${PORT}" --code="$(json "$bsetup" "['pairing_code']")" >/dev/null || result="pair_failed"
    $W option update home "$ORIGIN" >/dev/null
    $W option update siteurl "$ORIGIN" >/dev/null
    drafts='$all = wc_get_orders(["type" => "shop_order", "status" => ["checkout-draft"], "limit" => -1]); $marked = 0; foreach ($all as $o) { if ($o->get_meta("_bw_synthetic_run") !== "") { $marked++; } } echo count($all) . "/" . $marked;'
    customer_draft=$($W eval 'if (! array_key_exists("wc-checkout-draft", wc_get_order_statuses())) { echo 0; return; } $o = wc_create_order(); $o->set_status("checkout-draft"); $o->set_date_created(time() - 3600); $o->save(); echo $o->get_id();')

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
        seen=$($W eval 'echo (string) ((\BusinessWatchdog\WooCommerce\Storage\State::get("last_synthetic_check") ?: [])["run_id"] ?? "");')
        [ "$seen" = "$run_id" ] || result="${mode}_marker_not_verified"
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
    ok = attempt['status'] == 'failed' and attempt['error_code'] == 'site_failure' and any(s.startswith('payment_form:failed:site_failure') for s in attempt['steps']) and state['run_status'] == 'queued' and attempt['artifacts'] == ['image/jpeg:stored']
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

    synthetic=$($W eval "$drafts")
    echo "  drafts total/marked after checks: ${synthetic}"
    if [ "$customer_draft" != "0" ]; then
        total=${synthetic%/*}; marked=${synthetic#*/}
        [ "$total" = "$((marked + 1))" ] || result="unmarked_drafts(${synthetic})"
        cleaned=$($W business-watchdog cleanup-synthetic --advance=700)
        remaining=$($W eval "$drafts")
        echo "  cleanup: ${cleaned} remaining total/marked: ${remaining}"
        [ "$remaining" = "1/0" ] || result="cleanup_unexpected(${remaining})"
        [ "$($W eval "echo wc_get_order(${customer_draft}) ? 'kept' : 'deleted';")" = "kept" ] || result="customer_draft_deleted"
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
