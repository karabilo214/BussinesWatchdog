#!/bin/sh
# One real PayPal sandbox payment through SwagPayPal (ADR 0025, spike part 3): Store API order on the shop host,
# Shopware's handle-payment, the sandbox buyer approves in Chromium, PayPal returns to the shop, Shopware finalizes.
# Needs bw-shopware running with SwagPayPal configured (configure.py). Usage: BW_SW_CURRENCY=USD pay.sh
set -eu
cd "$(dirname "$0")"
DOCKER_APP_BIN=/Applications/Docker.app/Contents/Resources/bin
[ -d "$DOCKER_APP_BIN" ] && PATH="$DOCKER_APP_BIN:$PATH"
ENV_FILE=../../../../apps/backend/.env
value() { grep "^$1=" "$ENV_FILE" | cut -d= -f2-; }
SW_IP=$(docker inspect -f '{{.NetworkSettings.IPAddress}}' bw-shopware)
mkdir -p .out && chmod 777 .out
URL=$(python3 -I order.py | sed -n 's/^BW_ORDER=//p' | tee .order.json | python3 -c "import json,sys; print(json.load(sys.stdin)['redirect'])")
docker run --rm --add-host "shop-sw.example.com:${SW_IP}" -v "$(pwd)/approve.mjs:/app/approve.mjs:ro" -v "$(pwd)/.out:/out" \
    -e BW_APPROVE_URL="$URL" -e BW_SHOP_HOST=shop-sw.example.com -e BUYER_EMAIL="$(value WATCHDOG_DEV_PAYPAL_BUYER_EMAIL)" -e BUYER_PASSWORD="$(value WATCHDOG_DEV_PAYPAL_BUYER_PASSWORD)" \
    bw-browser-worker:smoke node approve.mjs | tail -1
python3 -I inspect.py
