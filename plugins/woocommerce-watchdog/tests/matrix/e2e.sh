#!/bin/sh
set -eu

cd "$(dirname "$0")"

DOCKER_APP_BIN=/Applications/Docker.app/Contents/Resources/bin
[ -d "$DOCKER_APP_BIN" ] && PATH="$DOCKER_APP_BIN:$PATH"

BACKEND_DIR="$(cd ../../../../apps/backend && pwd)"
HELPER="$(pwd)/backend-e2e.php"
SERVE_LOG="$(pwd)/.cache/serve.log"
mkdir -p .cache
PHP="${PHP:-/opt/homebrew/opt/php@8.4/bin/php}"
PORT="${BW_E2E_PORT:-8765}"
ENDPOINT="http://host.docker.internal:${PORT}"
TARGETS="${*:-$(cut -d= -f1 targets.env | tr '\n' ' ')}"
STATUS=0

backend() {
    (cd "$BACKEND_DIR" && env DB_HOST=127.0.0.1 "$@" "$PHP" artisan tinker --execute="require '${HELPER}';" 2>/dev/null) | sed -n 's/^BW_E2E_JSON=//p'
}

json() { python3 -c "import json,sys; print(json.loads(sys.argv[1])$2)" "$1"; }

(cd "$BACKEND_DIR" && DB_HOST=127.0.0.1 CACHE_STORE=database WATCHDOG_RATE_PAIRING_PER_HOUR=10000 "$PHP" artisan serve --host=0.0.0.0 --port="$PORT" > "$SERVE_LOG" 2>&1) &
SERVE_PID=$!
trap 'kill $SERVE_PID 2>/dev/null; pkill -f "artisan serve --host=0.0.0.0 --port=${PORT}" 2>/dev/null || true' EXIT
sleep 2

for target in $TARGETS; do
    BW_MATRIX_KEEP=1 BW_TEST_FILTER=install ./run.sh "$target" >/dev/null 2>&1 || { echo "FAIL $target (setup)"; STATUS=1; continue; }
    W="docker compose -p bwmatrix-${target} -f docker-compose.yml exec -T -u www-data wordpress php /bw-cache/wp-cli.phar --path=/var/www/html"
    BASE_URL="https://shop-${target}.example.test"

    setup=$(backend BW_E2E_ACTION=setup BW_E2E_BASE_URL="$BASE_URL")
    store_id=$(json "$setup" "['store_id']")
    code=$(json "$setup" "['pairing_code']")

    result="ok"
    $W business-watchdog pair --endpoint="$ENDPOINT" --code="$code" >/dev/null || result="pair_failed"

    if [ "$result" = "ok" ]; then
        verification=$(backend BW_E2E_ACTION=start-verification BW_E2E_STORE_ID="$store_id")
        vid=$(json "$verification" "['verification_id']")
        challenge=$(json "$verification" "['challenge']")
        $W business-watchdog heartbeat >/dev/null
        served=$(docker compose -p "bwmatrix-${target}" -f docker-compose.yml exec -T wordpress sh -c "curl -s -H 'Host: localhost' http://localhost/?rest_route=/business-watchdog/v1/challenge/${vid}" || true)
        [ "$served" = "$challenge" ] || result="challenge_mismatch(${served})"
    fi

    if [ "$result" = "ok" ]; then
        backend BW_E2E_ACTION=request-rotation BW_E2E_STORE_ID="$store_id" >/dev/null
        rotated=$($W business-watchdog heartbeat)
        $W business-watchdog heartbeat >/dev/null
        status=$(backend BW_E2E_ACTION=status BW_E2E_STORE_ID="$store_id")
        creds=$(json "$status" "['credentials']")
        [ "$creds" = "['revoked', 'active']" ] || result="rotation_unexpected(${rotated} ${creds})"
        [ "$(json "$status" "['integration_provider']")" = "woocommerce" ] || result="provider_unexpected"
    fi

    if [ "$result" = "ok" ]; then
        events_file="$(pwd)/.cache/events-${target}.json"
        $W eval-file /var/www/html/wp-content/plugins/business-watchdog/tests/matrix/e2e-orders.php 2>/dev/null | sed -n 's/^BW_EVENTS=//p' > "$events_file"
        validation=$(backend BW_E2E_ACTION=validate-events BW_E2E_EVENTS_FILE="$events_file")
        echo "  events: $(json "$validation" "['types']")"
        [ "$(json "$validation" "['failures']")" = "[]" ] || result="events_invalid($(json "$validation" "['failures']"))"
        for expected in order.snapshot:processing refund.snapshot:recorded refund.snapshot:deleted order.snapshot:on-hold order.deleted; do
            echo "$(json "$validation" "['types']")" | grep -q "'${expected}'" || result="missing_event(${expected})"
        done
    fi

    if [ "$result" = "ok" ]; then
        delivered=$($W business-watchdog deliver)
        remaining=$($W eval 'global $wpdb; echo (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bw_outbox");')
        projection=$(backend BW_E2E_ACTION=projection-status BW_E2E_STORE_ID="$store_id")
        echo "  delivery: ${delivered} remaining=${remaining}"
        echo "  backend: ${projection}"
        [ "$remaining" = "0" ] || result="outbox_not_drained(${remaining})"
        check=$(python3 - "$projection" <<'PY'
import json, sys
d = json.loads(sys.argv[1])
problems = []
if d['inbox'].get('processed', 0) == 0 or set(d['inbox']) != {'processed'}:
    problems.append('inbox=%s' % d['inbox'])
if d['refunds'] != [['5000', 'deleted']]:
    problems.append('refunds=%s' % d['refunds'])
eur = [o for o in d['orders'] if o[0] == 'EUR']
jpy = [o for o in d['orders'] if o[0] == 'JPY']
if not eur or eur[0][1:] != ['18400', 'processing', False, 'supported']:
    problems.append('eur=%s' % eur)
if not jpy or jpy[0][1] != '1500' or jpy[0][3] is not True or jpy[0][4] != 'unsupported':
    problems.append('jpy=%s' % jpy)
print('ok' if not problems else ';'.join(problems))
PY
)
        [ "$check" = "ok" ] || result="projection_unexpected(${check})"
    fi

    if [ "$result" = "ok" ]; then
        setup=$($W eval-file /var/www/html/wp-content/plugins/business-watchdog/tests/matrix/checkout-setup.php 2>/dev/null | sed -n 's/^BW_SETUP=//p')
        checkout=$(docker compose -p "bwmatrix-${target}" -f docker-compose.yml exec -T wordpress php /var/www/html/wp-content/plugins/business-watchdog/tests/matrix/checkout-driver.php "shop-${target}.example.test" "$(json "$setup" "['product_id']")" "$(json "$setup" "['checkout_page_id']")" | sed -n 's/^BW_CHECKOUT=//p')
        echo "  checkout: ${checkout}"
        $W business-watchdog payment-attempts --advance=2100 >/dev/null
        attempts=$($W eval 'global $wpdb; $rows = $wpdb->get_results("SELECT payment_method, outcome, failure_class FROM {$wpdb->prefix}bw_payment_attempts", ARRAY_A); $list = []; $totals = []; foreach ($rows as $r) { $list[] = $r["payment_method"] . ":" . ($r["outcome"] === null ? "open" : $r["outcome"]) . ":" . $r["failure_class"]; if ($r["outcome"] !== null) { $k = $r["payment_method"] . ":" . $r["outcome"]; $totals[$k] = ($totals[$k] ?? 0) + 1; } } sort($list); ksort($totals); echo wp_json_encode(["list" => $list, "totals" => (object) $totals]);')
        echo "  attempts: $(json "$attempts" "['list']")"
        $W business-watchdog deliver >/dev/null
        windows=$(backend BW_E2E_ACTION=attempt-windows BW_E2E_STORE_ID="$store_id")
        echo "  windows: ${windows}"
        check=$(python3 - "$attempts" "$windows" <<'PY'
import json, sys
local = json.loads(sys.argv[1])
remote = json.loads(sys.argv[2])
problems = []
items = local['list']
if any(':open:' in item for item in items):
    problems.append('open_attempts')
for required in ['bacs:on_hold:', 'bw_test_decline:failed:gateway_error', 'bacs:rejected_before_order:validation']:
    if required not in items:
        problems.append('missing ' + required)
if items.count('bacs:rejected_before_order:validation') != 2:
    problems.append('rejections')
if len(items) != 6:
    problems.append('count=%d' % len(items))
if remote['totals'] != local['totals']:
    problems.append('backend_totals_differ')
if set(remote['inbox']) != {'processed'}:
    problems.append('inbox=%s' % remote['inbox'])
print('ok' if not problems else ';'.join(problems))
PY
)
        [ "$check" = "ok" ] || result="attempts_unexpected(${check})"
    fi

    if [ "$result" = "ok" ]; then echo "PASS ${target} e2e"; else echo "FAIL ${target} e2e: ${result}"; STATUS=1; fi
    docker compose -p "bwmatrix-${target}" -f docker-compose.yml down -v >/dev/null 2>&1 || true
done

exit $STATUS
