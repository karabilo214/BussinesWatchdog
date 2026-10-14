#!/bin/sh
# Browser checks on a real Shopware storefront (ADR 0055): the worker image walks product → cart → checkout in each way
# of reaching payment methods (stop before sign-in, test customer, guest), a wrong test customer password and a sold-out
# product end as setup problems, and no order is ever created. Runs against its own database (BW_E2E_DATABASE, migrated
# fresh) so the worker never leases runs of the local stand. Usage: browser-e2e.sh [image ...]
set -eu
cd "$(dirname "$0")"
DOCKER_APP_BIN=/Applications/Docker.app/Contents/Resources/bin
[ -d "$DOCKER_APP_BIN" ] && PATH="$DOCKER_APP_BIN:$PATH"
BACKEND_DIR="$(cd ../../../../apps/backend && pwd)"
WORKER_DIR="$(cd ../../../../apps/browser-worker && pwd)"
HELPER="$(pwd)/backend-e2e.php"
PHP="${PHP:-/opt/homebrew/opt/php@8.4/bin/php}"
PORT="${BW_E2E_PORT:-8767}"
ORIGIN="http://shopware"
NETWORK=bw-sw-browser
DATABASE="${BW_E2E_DATABASE:-bw_browser_e2e}"
S3_ENV="DB_HOST=127.0.0.1 DB_DATABASE=${DATABASE} CACHE_STORE=database AWS_ENDPOINT=http://127.0.0.1:${S3_PORT:-9090} AWS_USE_PATH_STYLE_ENDPOINT=true"
mkdir -p .cache
SERVE_LOG="$(pwd)/.cache/serve.log"
STATUS=0

backend() { (cd "$BACKEND_DIR" && env $S3_ENV BW_E2E_ORIGIN="$ORIGIN" "$@" "$PHP" artisan tinker --execute="require '${HELPER}';" 2>/dev/null) | sed -n 's/^BW_E2E_JSON=//p'; }
shop() { python3 -I shop.py "$@" | sed -n 's/^BW_STORE=//p'; }
j() { python3 -c "import json,sys; print(json.loads(sys.argv[1])$2)" "$1"; }

docker build -q -t bw-browser-worker:e2e "$WORKER_DIR" >/dev/null
(cd ../../../.. && docker compose up -d s3 postgres >/dev/null && docker compose exec -T postgres sh -c "psql -U \"\$POSTGRES_USER\" -d postgres -tc \"SELECT 1 FROM pg_database WHERE datname = '${DATABASE}'\" | grep -q 1 || createdb -U \"\$POSTGRES_USER\" ${DATABASE}")
(cd "$BACKEND_DIR" && env $S3_ENV "$PHP" artisan migrate:fresh --force >/dev/null)
docker network create "$NETWORK" >/dev/null 2>&1 || true
(cd "$BACKEND_DIR" && env $S3_ENV "$PHP" artisan serve --host=0.0.0.0 --port="$PORT" > "$SERVE_LOG" 2>&1) &
SERVE_PID=$!
trap 'kill $SERVE_PID 2>/dev/null; pkill -f "artisan serve --host=0.0.0.0 --port=${PORT}" 2>/dev/null || true; docker rm -f bw-shopware >/dev/null 2>&1 || true' EXIT
sleep 2

for IMAGE in ${*:-dockware/play:6.6.10.6 dockware/play:6.7.2.2}; do
    docker rm -f bw-shopware >/dev/null 2>&1 || true
    docker run -d --name bw-shopware --network "$NETWORK" --network-alias shopware -p 127.0.0.1:8480:80 "$IMAGE" >/dev/null
    ../wait-ready.sh
    echo "=== ${IMAGE}"
    shopdata=$(shop setup "$ORIGIN")
    docker exec bw-shopware bash -lc 'cd /var/www/html && php bin/console cache:clear >/dev/null 2>&1'
    setup=$(backend BW_E2E_ACTION=setup)
    scenario=$(j "$setup" "['scenario_id']")
    token=$(j "$setup" "['worker_token']")
    product="${ORIGIN}/detail/$(j "$shopdata" "['product']")"
    sold_out="${ORIGIN}/detail/$(j "$shopdata" "['sold_out']")"
    email=$(j "$shopdata" "['email']")
    password=$(j "$shopdata" "['password']")
    before=$(shop counts)
    : > ".cache/worker.log"
    results=""

    check() {
        label="$1"; access="$2"; url="$3"; pw="$4"
        run=$(j "$(backend BW_E2E_ACTION=run BW_E2E_SCENARIO_ID="$scenario" BW_E2E_ACCESS="$access" BW_E2E_PRODUCT_URL="$url" BW_E2E_EMAIL="$email" BW_E2E_PASSWORD="$pw")" "['run_id']")
        docker run --rm --network "$NETWORK" --add-host host.docker.internal:host-gateway \
            --read-only --tmpfs /tmp --cap-drop ALL --security-opt no-new-privileges \
            -e BW_API_URL="http://host.docker.internal:${PORT}" -e BW_WORKER_TOKEN="$token" \
            -e BW_WORKER_INSECURE_LOCAL=1 -e NODE_ENV=development -e BW_RUN_ONCE=1 -e BW_WORKER_LOCATION=sw-e2e \
            bw-browser-worker:e2e >> .cache/worker.log 2>&1 || true
        state=$(backend BW_E2E_ACTION=status BW_E2E_RUN_ID="$run" BW_E2E_PASSWORD="$password")
        counts=$(shop counts)
        echo "  ${label}: ${state}"
        echo "    shop: ${counts}"
        results="${results}${label}|${state}|${counts}
"
    }

    check stop_before_sign_in stop_before_sign_in "$product" ""
    check test_customer test_customer "$product" "$password"
    check test_customer_again test_customer "$product" "$password"
    check wrong_password test_customer "$product" "wrong-${password}"
    check guest guest "$product" ""
    check sold_out stop_before_sign_in "$sold_out" ""
    after=$(shop counts)

    printf '%s' "$results" > .cache/results.txt
    python3 - "$before" "$after" .cache/results.txt <<'PY' || STATUS=1
import json, sys
before, after = json.loads(sys.argv[1]), json.loads(sys.argv[2])
runs = {}
for line in open(sys.argv[3]).read().strip().splitlines():
    label, state, counts = line.split('|')
    runs[label] = (json.loads(state), json.loads(counts))
def steps(label):
    return runs[label][0]['attempts'][0]['steps'] if runs[label][0]['attempts'] else []
checks = {
    'stops at sign-in without creating anything': runs['stop_before_sign_in'][0]['run_status'] == 'passed' and steps('stop_before_sign_in')[-1] == 'checkout:passed(sign_in_offered)' and len(steps('stop_before_sign_in')) == 4,
    'test customer reaches payment methods': runs['test_customer'][0]['run_status'] == 'passed' and steps('test_customer')[4] == 'sign_in:passed' and steps('test_customer')[5].startswith('payment_form:passed(methods_'),
    'test customer cart cleaned after the check': runs['test_customer'][1]['test_customer_cart_lines'] == 0 and runs['test_customer_again'][1]['test_customer_cart_lines'] == 0,
    'second test customer run passes too': runs['test_customer_again'][0]['run_status'] == 'passed',
    'wrong password is a test account problem': runs['wrong_password'][0]['run_status'] == 'blocked' and runs['wrong_password'][0]['run_error'] == 'test_account_rejected' and steps('wrong_password')[4] == 'sign_in:blocked:test_account_rejected(sign_in_rejected)',
    'guest reaches payment methods': runs['guest'][0]['run_status'] == 'passed' and steps('guest')[4] == 'guest_details:passed' and steps('guest')[5].startswith('payment_form:passed(methods_'),
    'guest run created exactly one guest customer': runs['guest'][1]['guests'] == before['guests'] + 1 and after['guests'] == before['guests'] + 1,
    'sold-out product is a test product problem': runs['sold_out'][0]['run_status'] == 'failed' and runs['sold_out'][0]['run_error'] == 'product_unavailable',
    'no order created': after['orders'] == before['orders'],
    'password never stored with results': not any(state['secret_in_results'] for state, _ in runs.values()),
}
for name, ok in checks.items():
    print(('  PASS ' if ok else '  FAIL ') + name)
sys.exit(0 if all(checks.values()) else 1)
PY
done

echo "RESULT $([ $STATUS -eq 0 ] && echo PASS || echo FAIL)"
exit $STATUS
