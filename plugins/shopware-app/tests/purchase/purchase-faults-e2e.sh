#!/bin/sh
# Local Shopware 6.6/6.7 purchase lab. Only the lab robot may submit an order.
set -eu
cd "$(dirname "$0")"
DOCKER_APP_BIN=/Applications/Docker.app/Contents/Resources/bin
[ -d "$DOCKER_APP_BIN" ] && PATH="$DOCKER_APP_BIN:$PATH"
export PATH
STATUS=0
trap 'docker rm -f bw-shopware >/dev/null 2>&1 || true' EXIT

for IMAGE in ${*:-bw-shopware:6.6.10.29 bw-shopware:6.7.15.1}; do
    docker rm -f bw-shopware >/dev/null 2>&1 || true
    docker run -d --name bw-shopware -p 127.0.0.1:8480:80 "$IMAGE" >/dev/null
    ../wait-ready.sh
    setup=$(python3 -I ../browser/shop.py setup http://localhost | sed -n 's/^BW_STORE=//p')
    product=$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["product"])' "$setup")
    test_email=$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["email"])' "$setup")
    test_password=$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["password"])' "$setup")
    echo "=== ${IMAGE}"

    before=$(docker exec bw-shopware mysql -h127.0.0.1 -uroot -proot shopware -N -e 'SELECT COUNT(*) FROM `order`' 2>/dev/null)
    for access in stop_before_sign_in test_customer guest; do
        docker run --rm --network container:bw-shopware --read-only --tmpfs /tmp --cap-drop ALL --security-opt no-new-privileges \
            -v "$(pwd)/scheduled-check.mjs:/app/scheduled-check.mjs:ro" \
            -v "$(pwd)/../../../../apps/browser-worker/src:/app/src:ro" \
            -e BW_SHOP_ORIGIN=http://localhost -e BW_PRODUCT_ID="$product" -e BW_CHECK_ACCESS="$access" \
            -e BW_TEST_EMAIL="$test_email" -e BW_TEST_PASSWORD="$test_password" \
            bw-browser-worker:e2e node /app/scheduled-check.mjs || STATUS=1
    done
    after_scheduled=$(docker exec bw-shopware mysql -h127.0.0.1 -uroot -proot shopware -N -e 'SELECT COUNT(*) FROM `order`' 2>/dev/null)
    [ "$after_scheduled" -eq "$before" ] || { echo 'scheduled check created an order'; STATUS=1; }
    for mode in ${BW_PURCHASE_MODES:-healthy product_button cart_button cart_script cart_price cannot_buy checkout_response_missing}; do
        echo "${mode}:"
        docker run --rm --network container:bw-shopware --read-only --tmpfs /tmp --cap-drop ALL --security-opt no-new-privileges \
            -v "$(pwd)/purchase-robot.mjs:/app/purchase-robot.mjs:ro" \
            -e BW_PURCHASE_LAB=1 -e BW_SHOP_ORIGIN=http://localhost -e BW_PRODUCT_ID="$product" -e BW_FAULT_MODE="$mode" \
            bw-browser-worker:e2e node /app/purchase-robot.mjs || STATUS=1
    done
    after=$(docker exec bw-shopware mysql -h127.0.0.1 -uroot -proot shopware -N -e 'SELECT COUNT(*) FROM `order`' 2>/dev/null)
    echo "orders_before=${before} orders_after=${after}"
    if [ -z "${BW_PURCHASE_MODES:-}" ]; then
        [ "$after" -eq "$((before + 2))" ] || { echo 'unexpected order count'; STATUS=1; }
    fi
done

exit "$STATUS"
