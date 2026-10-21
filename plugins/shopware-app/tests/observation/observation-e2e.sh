#!/bin/sh
# Checkout observation script of the Shopware app (ADR 0057/0062) against a real Shopware: the store's app archive is
# installed as the owner does; real shoppers (a browser that is not the check) go through checkout. Their browsers talk
# only to the shop: the app keeps the reports in its own entity and the service collects them through the Admin API.
# Not observed while the check stops before sign-in; observed with the guest way: stages counted, three broken submits
# open CHECKOUT_SUBMIT_FAILING, a real order resolves it; the browser check finds the script and is not counted itself.
# Own database (BW_E2E_DATABASE, migrated fresh). Usage: observation-e2e.sh [image ...]
set -eu
cd "$(dirname "$0")"
DOCKER_APP_BIN=/Applications/Docker.app/Contents/Resources/bin
[ -d "$DOCKER_APP_BIN" ] && PATH="$DOCKER_APP_BIN:$PATH"
export PATH
BACKEND_DIR="$(cd ../../../../apps/backend && pwd)"
WORKER_DIR="$(cd ../../../../apps/browser-worker && pwd)"
HELPER="$(pwd)/backend-e2e.php"
APP_HELPER="$(cd ../chain && pwd)/backend-e2e.php"
PHP="${PHP:-/opt/homebrew/opt/php@8.4/bin/php}"
PORT="${BW_E2E_PORT:-8771}"
SERVICE="http://host.docker.internal:${PORT}"
DATABASE="${BW_E2E_DATABASE:-bw_observation_e2e}"
ENV="DB_HOST=127.0.0.1 DB_DATABASE=${DATABASE} CACHE_STORE=database QUEUE_CONNECTION=sync APP_URL=${SERVICE} WATCHDOG_DEV_SHOPWARE_ENDPOINT=http://127.0.0.1:8480"
mkdir -p .cache
OUT="$(pwd)/.cache"
STATUS=0

backend() { (cd "$BACKEND_DIR" && env $ENV "$@" "$PHP" artisan tinker --execute="require '${HELPER}';" 2>/dev/null) | sed -n 's/^BW_E2E_JSON=//p'; }
app_backend() { (cd "$BACKEND_DIR" && env $ENV "$@" "$PHP" artisan tinker --execute="require '${APP_HELPER}';" 2>/dev/null) | sed -n 's/^BW_E2E_JSON=//p'; }
shop() { (cd ../app && python3 -I shop.py "$@") | sed -n 's/^BW_SHOP=//p'; }
consume() { docker exec bw-shopware bash -lc 'cd /var/www/html && php bin/console messenger:consume async --time-limit=20 -q' || true; }
customer() { docker run --rm --network container:bw-shopware -v "$(pwd)":/app/e2e:ro --entrypoint node bw-browser-worker:e2e /app/e2e/customer.mjs "$1" "$2" 2>&1 | sed -n 's/^BW_CUSTOMER=//p'; }
j() { python3 -c "import json,sys; print(json.loads(sys.argv[1])$2)" "$1"; }

docker build -q -t bw-browser-worker:e2e "$WORKER_DIR" >/dev/null
(cd ../../../.. && docker compose up -d postgres >/dev/null && docker compose exec -T postgres sh -c "psql -U \"\$POSTGRES_USER\" -d postgres -tc \"SELECT 1 FROM pg_database WHERE datname = '${DATABASE}'\" | grep -q 1 || createdb -U \"\$POSTGRES_USER\" ${DATABASE}")
(cd "$BACKEND_DIR" && env $ENV "$PHP" artisan serve --host=0.0.0.0 --port="$PORT" > "$OUT/serve.log" 2>&1) &
trap 'pkill -f "artisan serve --host=0.0.0.0 --port=${PORT}" 2>/dev/null || true; docker rm -f bw-shopware >/dev/null 2>&1 || true' EXIT
sleep 2

for IMAGE in ${*:-dockware/play:6.6.10.6 dockware/play:6.7.2.2}; do
    (cd "$BACKEND_DIR" && env $ENV "$PHP" artisan migrate:fresh --force >/dev/null)
    docker rm -f bw-shopware >/dev/null 2>&1 || true
    docker run -d --name bw-shopware -p 127.0.0.1:8480:80 "$IMAGE" >/dev/null
    ../wait-ready.sh
    echo "=== ${IMAGE}"
    package=$(app_backend BW_E2E_ACTION=app-package BW_E2E_BASE_URL=https://localhost BW_E2E_ZIP="$OUT/BusinessWatchdog.zip")
    store=$(j "$package" "['store_id']")
    installed=$(shop upload "$OUT/BusinessWatchdog.zip")
    app=$(app_backend BW_E2E_ACTION=app-state BW_E2E_STORE_ID="$store")
    docker exec bw-shopware bash -lc 'cd /var/www/html && php bin/console cache:clear >/dev/null 2>&1'
    product="http://localhost/detail/$(docker exec bw-shopware bash -lc "mysql -h127.0.0.1 -uroot -proot shopware -N -e \"select lower(hex(id)) from product where product_number='SWDEMO10001'\" 2>/dev/null")"
    echo "  app: $(j "$installed" "['active']") $(j "$app" "['status']")"

    backend BW_E2E_ACTION=scenario BW_E2E_STORE_ID="$store" BW_E2E_ACCESS=stop_before_sign_in BW_E2E_PRODUCT_URL="$product" >/dev/null
    backend BW_E2E_ACTION=collect BW_E2E_STORE_ID="$store" >/dev/null
    off_customer=$(customer buy "$product")
    off=$(backend BW_E2E_ACTION=state BW_E2E_STORE_ID="$store")

    backend BW_E2E_ACTION=scenario BW_E2E_STORE_ID="$store" BW_E2E_ACCESS=guest BW_E2E_PRODUCT_URL="$product" >/dev/null
    collected=$(backend BW_E2E_ACTION=collect BW_E2E_STORE_ID="$store")
    sleep $(( 302 - $(date +%s) % 300 ))
    broken=""
    for i in 1 2 3; do broken="$broken $(customer broken "$product")"; done
    first_broken=$(echo "$broken" | awk '{print $1}')
    failing=$(backend BW_E2E_ACTION=state BW_E2E_STORE_ID="$store")
    bought=$(customer buy "$product")
    consume
    recovered=$(backend BW_E2E_ACTION=state BW_E2E_STORE_ID="$store")

    check=$(backend BW_E2E_ACTION=check BW_E2E_STORE_ID="$store")
    docker run --rm --network container:bw-shopware --read-only --tmpfs /tmp --cap-drop ALL --security-opt no-new-privileges \
        -e BW_API_URL="$SERVICE" -e BW_WORKER_TOKEN="$(j "$check" "['worker_token']")" -e BW_WORKER_INSECURE_LOCAL=1 -e NODE_ENV=development -e BW_RUN_ONCE=1 \
        bw-browser-worker:e2e > "$OUT/worker.log" 2>&1 || true
    checked=$(backend BW_E2E_ACTION=check-status BW_E2E_STORE_ID="$store" BW_E2E_RUN_ID="$(j "$check" "['run_id']")")
    after_check=$(backend BW_E2E_ACTION=state BW_E2E_STORE_ID="$store")
    left=$(docker exec bw-shopware bash -lc "mysql -h127.0.0.1 -uroot -proot shopware -N -e 'select count(*) from custom_entity_bw_checkout_report' 2>/dev/null")

    backend BW_E2E_ACTION=layout BW_E2E_STORE_ID="$store" BW_E2E_LAYOUT='{"order_form":"form#confirmOrderForm","submit_button":"button#confirmFormSubmit","payment_method":"input.payment-method-input"}' >/dev/null
    backend BW_E2E_ACTION=collect BW_E2E_STORE_ID="$store" >/dev/null
    docker exec bw-shopware bash -lc 'cd /var/www/html && php bin/console cache:clear >/dev/null 2>&1'
    custom=$(customer peek "$product")
    backend BW_E2E_ACTION=layout BW_E2E_STORE_ID="$store" BW_E2E_LAYOUT='{"submit_button":"#theme-renamed-submit"}' >/dev/null
    backend BW_E2E_ACTION=collect BW_E2E_STORE_ID="$store" >/dev/null
    docker exec bw-shopware bash -lc 'cd /var/www/html && php bin/console cache:clear >/dev/null 2>&1'
    renamed=$(customer peek "$product")
    config_answer=$(docker exec bw-shopware bash -lc "curl -s -D - 'http://localhost/storefront/script/bw-observe?config=1'" | tr -d '\r' | grep -i -E '^cache-control|^\{' | tr '\n' ' ')

    for v in off_customer off collected failing bought recovered checked after_check left custom renamed config_answer; do eval "echo \"  $v: \$$v\""; done
    echo "  broken shoppers:$broken"
    python3 - "$off_customer" "$off" "$failing" "$bought" "$recovered" "$check" "$checked" "$after_check" "$collected" "$first_broken" "$custom" "$renamed" "$left" "host.docker.internal:${PORT}" "$config_answer" <<'PY' || STATUS=1
import json, sys
off_customer, off, failing, bought, recovered, check, checked, after_check, collected, first_broken, custom, renamed = [json.loads(a) for a in sys.argv[1:13]]
left, service_host, config_answer = int(sys.argv[13]), sys.argv[14], sys.argv[15]
shoppers = [off_customer, first_broken, bought]
c, r, a = failing['counts'], recovered['counts'], after_check['counts']
checks = {
    'the service set the app to observe': collected['observation']['observe'] is True and collected['observation']['last_error'] is None,
    'shoppers\' browsers contacted only the shop, never the service': all(service_host not in s['hosts'] for s in shoppers) and all(h.split(':')[0] in ('localhost',) for s in shoppers for h in s['hosts']),
    'observed shoppers reported to the app endpoint on the shop': first_broken['observe_requests'] >= 3 and bought['observe_requests'] >= 2,
    'not observed: the script reads the setting and sends no report': off_customer['observe_requests'] == 0 and off_customer['config_requests'] >= 1,
    'the setting is read from the shop': '"observe":true' in config_answer and 'theme-renamed-submit' in config_answer,
    'a custom layout reaches the script and its elements are found': custom['confirm']['layout'] == 'custom' and custom['confirm']['config'] == 'loaded' and custom['confirm']['found']['form'] and custom['confirm']['found']['submit'] and custom['confirm']['found']['payment_methods'] > 0 and any('POST' in r for r in custom['reports']),
    'a renamed submit button is reported as not found, without form_ready': renamed['confirm']['layout'] == 'custom' and renamed['confirm']['found']['submit'] is False and renamed['confirm']['found']['form'] is True,
    'collected reports deleted in the shop': left == 0 and after_check['observation']['last_error'] is None,
    'script loaded from the app on the confirm page': off_customer['confirm'] is not None and off_customer['confirm']['page'] == 'confirm',
    'not observed while the check stops before sign-in': off_customer['confirm']['state'] == 'available' and sum(off['counts'].values()) == 0 and off['coverage'] == 'check_does_not_reach_payment',
    'broken submits counted (shown, loaded, ready, sent, stalled)': c['confirm_rendered'] >= 3 and c['script_loaded'] >= 3 and c['form_ready'] >= 3 and c['submit_clicked'] == 3 and c['submit_stalled'] == 3 and c['finish_reached'] == 0,
    'three broken submits open the incident (an earlier order does not hide them)': failing['incidents'] == ['CHECKOUT_SUBMIT_FAILING:open'] and failing['attempts'] == off['attempts'] == 1,
    'a real order reaches the finish page': bought['url'].endswith('/checkout/finish') and bought['after'] is not None and bought['after']['page'] == 'finish',
    'finish counted and the order seen as an attempt': r['finish_reached'] == 1 and r['finish_rendered'] == 1 and r['submit_clicked'] == 4 and recovered['attempts'] >= 1,
    'the order resolves the incident': recovered['incidents'] == ['CHECKOUT_SUBMIT_FAILING:resolved'],
    'coverage observing': recovered['coverage'] == 'observing',
    'the browser check expects and finds the script and its elements': check['expect_script'] is True and checked['run_status'] == 'passed' and any(x.get('code') == 'checkout_script_present' and x.get('passed') for x in checked['script']) and any(x.get('code') == 'checkout_script_elements' and x.get('passed') and x.get('detail_code') == 'layout_default' for x in checked['script']),
    'the check itself is not counted and not reported as an error': a == r and not any(e.startswith('blocked_origin') for e in checked['errors']),
}
for name, ok in checks.items():
    print(('  PASS ' if ok else '  FAIL ') + name)
sys.exit(0 if all(checks.values()) else 1)
PY
done

echo "RESULT $([ $STATUS -eq 0 ] && echo PASS || echo FAIL)"
exit $STATUS
