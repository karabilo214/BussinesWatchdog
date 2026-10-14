#!/bin/sh
# Checkout observation script of the WooCommerce plugin (ADR 0059) with real shoppers' browsers on the matrix: the
# service tells the plugin to observe through the heartbeat; Classic checkouts failing three times open
# CHECKOUT_SUBMIT_FAILING, a real order (order-received page, layer 1 attempt) resolves it; the Blocks checkout is
# observed too; the browser check finds the script and is not counted. Own database (BW_E2E_DATABASE, migrated fresh).
# Usage: observation-e2e.sh [target...]
set -eu
cd "$(dirname "$0")"
DOCKER_APP_BIN=/Applications/Docker.app/Contents/Resources/bin
[ -d "$DOCKER_APP_BIN" ] && PATH="$DOCKER_APP_BIN:$PATH"
BACKEND_DIR="$(cd ../../../../apps/backend && pwd)"
WORKER_DIR="$(cd ../../../../apps/browser-worker && pwd)"
PHP="${PHP:-/opt/homebrew/opt/php@8.4/bin/php}"
PORT="${BW_E2E_PORT:-8772}"
DATABASE="${BW_E2E_DATABASE:-bw_wc_observation_e2e}"
ENV="DB_HOST=127.0.0.1 DB_DATABASE=${DATABASE} CACHE_STORE=database QUEUE_CONNECTION=sync AWS_ENDPOINT=http://127.0.0.1:${S3_PORT:-9090} AWS_USE_PATH_STYLE_ENDPOINT=true"
TARGETS="${*:-$(cut -d= -f1 targets.env | tr '\n' ' ')}"
ORIGIN="http://wordpress"
mkdir -p .cache
HERE="$(pwd)"
OUT="$(pwd)/.cache"
STATUS=0

backend() { (cd "$BACKEND_DIR" && env $ENV "$@" "$PHP" artisan tinker --execute="require '${HERE}/backend-e2e.php';" 2>/dev/null) | sed -n 's/^BW_E2E_JSON=//p'; }
observed() { (cd "$BACKEND_DIR" && env $ENV "$@" "$PHP" artisan tinker --execute="require '${HERE}/observation-backend.php';" 2>/dev/null) | sed -n 's/^BW_E2E_JSON=//p'; }
j() { python3 -c "import json,sys; print(json.loads(sys.argv[1])$2)" "$1"; }

docker build -q -t bw-browser-worker:e2e "$WORKER_DIR" >/dev/null
(cd ../../../.. && docker compose up -d postgres s3 >/dev/null && docker compose exec -T postgres sh -c "psql -U \"\$POSTGRES_USER\" -d postgres -tc \"SELECT 1 FROM pg_database WHERE datname = '${DATABASE}'\" | grep -q 1 || createdb -U \"\$POSTGRES_USER\" ${DATABASE}")
(cd "$BACKEND_DIR" && env $ENV "$PHP" artisan serve --host=0.0.0.0 --port="$PORT" > "$OUT/observation-serve.log" 2>&1) &
trap 'pkill -f "artisan serve --host=0.0.0.0 --port=${PORT}" 2>/dev/null || true' EXIT
sleep 2

for target in $TARGETS; do
    (cd "$BACKEND_DIR" && env $ENV "$PHP" artisan migrate:fresh --force >/dev/null)
    BW_MATRIX_KEEP=1 BW_TEST_FILTER=install ./run.sh "$target" >/dev/null 2>&1 || { echo "FAIL $target (setup)"; STATUS=1; continue; }
    W="docker compose -p bwmatrix-${target} -f docker-compose.yml exec -T -u www-data wordpress php /bw-cache/wp-cli.phar --path=/var/www/html"
    network="bwmatrix-${target}_default"
    $W config set AUTOMATIC_UPDATER_DISABLED true --raw --type=constant >/dev/null 2>&1 || true
    echo "=== ${target}"

    setup=$($W eval-file /var/www/html/wp-content/plugins/business-watchdog/tests/matrix/checkout-setup.php 2>/dev/null | sed -n 's/^BW_SETUP=//p')
    product=$(j "$setup" "['product_id']"); checkout=$(j "$setup" "['checkout_page_id']"); blocks=$(j "$setup" "['blocks_checkout_page_id']")
    bsetup=$(backend BW_E2E_ACTION=browser-setup BW_E2E_BASE_URL="https://shop-${target}.example.test" BW_E2E_ORIGIN="$ORIGIN" BW_E2E_PRODUCT_URL="${ORIGIN}/?p=${product}" BW_E2E_CART_URL="${ORIGIN}/?page_id=$(j "$setup" "['cart_page_id']")" BW_E2E_CHECKOUT_URL="${ORIGIN}/?page_id=${checkout}")
    store=$(j "$bsetup" "['store_id']"); scenario=$(j "$bsetup" "['scenario_id']"); token=$(j "$bsetup" "['worker_token']")
    $W business-watchdog pair --endpoint="http://host.docker.internal:${PORT}" --code="$(j "$bsetup" "['pairing_code']")" >/dev/null
    $W option update home "$ORIGIN" >/dev/null
    $W option update siteurl "$ORIGIN" >/dev/null
    $W business-watchdog heartbeat >/dev/null
    flag=$($W option get bw_checkout_observation --format=json 2>/dev/null || echo null)

    customer() { docker run --rm --network "$network" -v "$(pwd)":/app/e2e:ro --entrypoint node bw-browser-worker:e2e /app/e2e/observation-customer.mjs "$1" "$ORIGIN" "$product" "$checkout" "$blocks" 2>&1 | sed -n 's/^BW_CUSTOMER=//p'; }
    report() { $W business-watchdog payment-attempts --advance=2000 >/dev/null; $W business-watchdog checkout-observation --advance=400 >/dev/null; $W business-watchdog deliver >/dev/null; }

    broken=""; for i in 1 2 3; do broken="$broken $(customer broken)"; done
    report; failing=$(observed BW_E2E_ACTION=state BW_E2E_STORE_ID="$store")
    bought=$(customer buy)
    report; recovered=$(observed BW_E2E_ACTION=state BW_E2E_STORE_ID="$store")
    blocks_shopper='{"skipped":true}'
    if [ "$blocks" != "0" ]; then
        $W option update woocommerce_checkout_page_id "$blocks" >/dev/null
        blocks_shopper=$(customer blocks)
        $W option update woocommerce_checkout_page_id "$checkout" >/dev/null
    fi
    report; before_check=$(observed BW_E2E_ACTION=state BW_E2E_STORE_ID="$store")
    run=$(j "$(backend BW_E2E_ACTION=browser-run BW_E2E_SCENARIO_ID="$scenario" BW_E2E_CHECKOUT_URL="${ORIGIN}/?page_id=${checkout}")" "['run_id']")
    docker run --rm --network "$network" --add-host host.docker.internal:host-gateway --read-only --tmpfs /tmp --cap-drop ALL --security-opt no-new-privileges \
        -e BW_API_URL="http://host.docker.internal:${PORT}" -e BW_WORKER_TOKEN="$token" -e BW_WORKER_INSECURE_LOCAL=1 -e NODE_ENV=development -e BW_RUN_ONCE=1 \
        bw-browser-worker:e2e > "$OUT/observation-worker-${target}.log" 2>&1 || true
    checked=$(observed BW_E2E_ACTION=check-status BW_E2E_STORE_ID="$store" BW_E2E_RUN_ID="$run")
    report; after_check=$(observed BW_E2E_ACTION=state BW_E2E_STORE_ID="$store")

    for v in flag broken failing bought recovered blocks_shopper before_check checked after_check; do eval "echo \"  $v: \$$v\"" | cut -c1-700; done
    python3 - "$flag" "$failing" "$bought" "$recovered" "$blocks_shopper" "$before_check" "$checked" "$after_check" "$blocks" <<'PY' || STATUS=1
import json, sys
flag, failing, bought, recovered, blocks_shopper, before_check, checked, after_check = [json.loads(a) for a in sys.argv[1:9]]
has_blocks = sys.argv[9] != '0'
f, r, b = failing['counts'], recovered['counts'], before_check['counts']
checks = {
    'the service told the plugin to observe (heartbeat)': isinstance(flag, dict) and flag.get('observe') is True,
    'failing checkouts counted by the plugin and the script': f['confirm_rendered'] >= 3 and f['script_loaded'] >= 3 and f['form_ready'] >= 3 and f['submit_clicked'] == 3 and f['returned_with_error'] == 3,
    'three failing checkouts open the incident (no order)': failing['incidents'] == ['CHECKOUT_SUBMIT_FAILING:open'] and failing['attempts'] == 0,
    'a real order reaches the order-received page': 'order-received' in bought.get('url', '') and (bought.get('after') or {}).get('page') == 'finish',
    'finish counted and the order reported as an attempt': r['finish_reached'] == 1 and r['finish_rendered'] == 1 and r['submit_clicked'] == 4 and recovered['attempts'] >= 1,
    'the order resolves the incident': recovered['incidents'] == ['CHECKOUT_SUBMIT_FAILING:resolved'],
    'Blocks checkout observed': (not has_blocks) or ((blocks_shopper.get('after') or {}).get('page') == 'checkout' and b['form_ready'] == r['form_ready'] + 1 and b['script_loaded'] == r['script_loaded'] + 1),
    'coverage observing': before_check['coverage'] == 'observing',
    'the browser check expects and finds the script': checked['expect_script'] is True and checked['run_status'] == 'passed' and any(x.get('code') == 'checkout_script_present' and x.get('passed') for x in checked['script']),
    'the browser check is not counted': after_check['counts'] == before_check['counts'],
}
for name, ok in checks.items():
    print(('  PASS ' if ok else '  FAIL ') + name)
sys.exit(0 if all(checks.values()) else 1)
PY
    [ "${BW_E2E_KEEP:-0}" = "1" ] || docker compose -p "bwmatrix-${target}" -f docker-compose.yml down -v >/dev/null 2>&1 || true
done

echo "RESULT $([ $STATUS -eq 0 ] && echo PASS || echo FAIL)"
exit $STATUS
