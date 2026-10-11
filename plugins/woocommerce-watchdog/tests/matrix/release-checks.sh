#!/bin/sh
# Release checks on the package exactly as shipped (spec: platforms and safe integrations, §8–9): install the previous
# package, upgrade to the new one, roll back and forward again, keep checkout working while the service is unreachable
# (DNS failure and a silent address), both emergency switches, WooCommerce missing, deactivation and repeated uninstall
# without network. Usage: release-checks.sh [target] — BW_PREVIOUS_REF picks the previous package (default origin/main).
set -eu

cd "$(dirname "$0")"
DOCKER_APP_BIN=/Applications/Docker.app/Contents/Resources/bin
[ -d "$DOCKER_APP_BIN" ] && PATH="$DOCKER_APP_BIN:$PATH"
TARGET="${1:-latest}"
PREVIOUS_REF="${BW_PREVIOUS_REF:-origin/main}"
PLUGIN_DIR="$(cd ../.. && pwd)"
REPO_DIR="$(cd ../../../.. && pwd)"
line=$(grep "^${TARGET}=" targets.env | cut -d= -f2-)
[ -n "$line" ] || { echo "Unknown target: $TARGET"; exit 2; }
export WP_IMAGE=$(echo "$line" | cut -d'|' -f1)
WC_VERSION=$(echo "$line" | cut -d'|' -f2)
PROJECT="bwrelease-${TARGET}"
RELEASE=.cache/release
rm -rf "$RELEASE" && mkdir -p "$RELEASE/previous-src"
[ -f ".cache/woocommerce.${WC_VERSION}.zip" ] || curl -fsSL -o ".cache/woocommerce.${WC_VERSION}.zip" "https://downloads.wordpress.org/plugin/woocommerce.${WC_VERSION}.zip"
[ -f .cache/wp-cli.phar ] || curl -fsSL -o .cache/wp-cli.phar "https://github.com/wp-cli/wp-cli/releases/download/v2.11.0/wp-cli-2.11.0.phar"

NEW_ZIP=$("$PLUGIN_DIR/bin/build-release.sh" "$PLUGIN_DIR" "$RELEASE/new" | sed -n 's/^BW_RELEASE_ZIP=//p')
git -C "$REPO_DIR" archive "$PREVIOUS_REF" plugins/woocommerce-watchdog | tar -x -C "$RELEASE/previous-src"
OLD_ZIP=$("$PLUGIN_DIR/bin/build-release.sh" "$RELEASE/previous-src/plugins/woocommerce-watchdog" "$RELEASE/previous" | sed -n 's/^BW_RELEASE_ZIP=//p')
[ -n "$NEW_ZIP" ] && [ -n "$OLD_ZIP" ] || { echo "package build failed"; exit 1; }
NEW="/bw-release/new/$(basename "$NEW_ZIP")"
OLD="/bw-release/previous/$(basename "$OLD_ZIP")"

compose() { docker compose -p "$PROJECT" -f docker-compose.release.yml "$@"; }
wp() { compose exec -T -u www-data wordpress php /bw-cache/wp-cli.phar --path=/var/www/html "$@"; }
probe() { compose exec -T -u www-data -e BW_PROBE="$1" -e BW_PROBE_ENDPOINT="${2:-}" wordpress php /bw-cache/wp-cli.phar --path=/var/www/html eval-file /bw-matrix/release-probe.php 2>/dev/null | sed -n 's/^BW_PROBE=//p'; }
field() { python3 -c "import json,sys; v=json.loads(sys.argv[1]).get(sys.argv[2]); print(json.dumps(v))" "$1" "$2"; }
RESULTS=""
FAILED=0
check() { if [ "$2" = "$3" ]; then RESULTS="${RESULTS}PASS $1\n"; else RESULTS="${RESULTS}FAIL $1 (expected $3, got $2)\n"; FAILED=1; fi; }

compose down -v >/dev/null 2>&1 || true
trap '[ "${BW_RELEASE_KEEP:-0}" = "1" ] || compose down -v >/dev/null 2>&1 || true' EXIT
compose up -d --wait >/dev/null
i=0; until compose exec -T wordpress test -f /var/www/html/wp-config.php 2>/dev/null; do i=$((i + 1)); [ $i -gt 60 ] && { echo "wordpress did not start"; exit 1; }; sleep 1; done
wp core install --url="https://shop-release.example.test" --title="BW release" --admin_user=admin --admin_password=admin --admin_email=admin@example.test --skip-email >/dev/null
wp plugin install "/bw-cache/woocommerce.${WC_VERSION}.zip" --activate >/dev/null
wp option update woocommerce_coming_soon no >/dev/null 2>&1 || true
echo "=== ${TARGET}: ${WP_IMAGE} + WooCommerce ${WC_VERSION}; previous package from ${PREVIOUS_REF}"

wp plugin install "$OLD" --activate >/dev/null
probe connect "https://bw-unreachable.invalid" >/dev/null
before=$(probe order)
check "previous package installs and records orders" "$(field "$before" loaded)" "true"
rows_before=$(field "$before" outbox_rows)

wp plugin install "$NEW" --force >/dev/null 2>&1
after=$(probe state)
check "upgrade keeps the plugin active on the new version" "$(field "$after" version)" '"0.2.0"'
check "upgrade keeps the queued events" "$(field "$after" outbox_rows)" "$rows_before"
check "upgrade keeps the connection" "$(field "$after" connection)" "true"
check "schema is current after upgrade" "$(field "$after" schema_current)" "true"

wp plugin install "$OLD" --force >/dev/null 2>&1
back=$(probe order)
check "rollback to the previous package keeps working" "$(field "$back" order_saved)" "true"
check "rollback keeps the queued events" "$([ "$(field "$back" outbox_rows)" -ge "$rows_before" ] && echo yes)" "yes"
wp plugin install "$NEW" --force >/dev/null 2>&1
forward=$(probe state)
check "upgrade again after rollback" "$(field "$forward" version)" '"0.2.0"'

setup=$(wp eval-file /bw-matrix/checkout-setup.php | sed -n 's/^BW_SETUP=//p')
product=$(python3 -c "import json,sys; print(json.loads(sys.argv[1])['product_id'])" "$setup")
page=$(python3 -c "import json,sys; print(json.loads(sys.argv[1])['checkout_page_id'])" "$setup")
for endpoint in "https://bw-unreachable.invalid" "http://10.255.255.1:9"; do
    probe connect "$endpoint" >/dev/null
    start=$(date +%s)
    result=$(compose exec -T wordpress php /bw-matrix/checkout-driver.php shop-release.example.test "$product" "$page" | sed -n 's/^BW_CHECKOUT=//p')
    elapsed=$(( $(date +%s) - start ))
    check "checkout succeeds while the service is unreachable (${endpoint})" "$(python3 -c "import json,sys; print(json.loads(sys.argv[1])['classic']['bacs'])" "$result")" "success"
    check "checkout does not wait for the service (${endpoint}, ${elapsed}s for 6 submissions)" "$([ "$elapsed" -lt 30 ] && echo yes)" "yes"
done
deliver=$(wp business-watchdog deliver 2>/dev/null || true)
check "delivery to an unreachable service backs off instead of failing" "$(python3 -c "import json,sys; d=json.loads(sys.argv[1]); print(d.get('retry',0)>0 and d.get('delivered',0)==0)" "$deliver")" "True"

pre=$(probe state)
wp business-watchdog stop >/dev/null
stopped=$(probe order)
check "soft stop: no schedules" "$(field "$stopped" heartbeat_scheduled)" "false"
check "soft stop: orders saved, nothing queued" "$(field "$stopped" order_saved):$(field "$stopped" outbox_rows)" "true:$(field "$pre" outbox_rows)"
wp business-watchdog resume >/dev/null
resumed=$(probe state)
check "resume restores the schedules" "$(field "$resumed" heartbeat_scheduled)" "true"

wp config set BUSINESS_WATCHDOG_DISABLED true --raw --type=constant >/dev/null
hard=$(probe order)
check "hard switch: plugin code not loaded" "$(field "$hard" loaded)" "false"
check "hard switch: orders saved, nothing queued" "$(field "$hard" order_saved):$(field "$hard" outbox_rows)" "true:$(field "$resumed" outbox_rows)"
check "hard switch: storefront responds" "$(compose exec -T wordpress curl -s -o /dev/null -w '%{http_code}' -H 'Host: shop-release.example.test' -H 'X-Forwarded-Proto: https' http://localhost/)" "200"
wp config delete BUSINESS_WATCHDOG_DISABLED --type=constant >/dev/null

wp plugin deactivate woocommerce >/dev/null
nowc=$(probe state)
check "without WooCommerce: no fatal error, plugin idle" "$(field "$nowc" loaded)" "true"
check "without WooCommerce: storefront responds" "$(compose exec -T wordpress curl -s -o /dev/null -w '%{http_code}' -H 'Host: shop-release.example.test' -H 'X-Forwarded-Proto: https' http://localhost/)" "200"
wp plugin activate woocommerce >/dev/null

wp plugin deactivate business-watchdog >/dev/null 2>&1 && deactivated=yes || deactivated=no
check "deactivation without network" "$deactivated" "yes"
check "deactivation clears schedules" "$(field "$(probe state)" heartbeat_scheduled)" "false"
twice=$(probe uninstall-twice)
check "uninstall runs twice without errors and removes the connection" "$(field "$twice" uninstalled_twice):$(field "$twice" connection)" "true:false"
wp plugin uninstall business-watchdog >/dev/null 2>&1 && removed=yes || removed=no
check "uninstall through WordPress without network" "$removed" "yes"
check "storefront responds after uninstall" "$(compose exec -T wordpress curl -s -o /dev/null -w '%{http_code}' -H 'Host: shop-release.example.test' -H 'X-Forwarded-Proto: https' http://localhost/)" "200"
fatal=$(compose exec -T wordpress sh -c 'grep -c "PHP Fatal" /tmp/wp-debug.log 2>/dev/null || true')
check "no PHP fatal errors in the debug log" "${fatal:-0}" "0"

printf "%b" "$RESULTS"
echo "RESULT $([ $FAILED -eq 0 ] && echo PASS || echo FAIL)"
exit $FAILED
