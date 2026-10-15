#!/bin/sh
# Disconnect a disposable Shopware container after configuring valid PayPal Sandbox credentials.
set -eu
cd "$(dirname "$0")"
DOCKER_APP_BIN=/Applications/Docker.app/Contents/Resources/bin
[ -d "$DOCKER_APP_BIN" ] && PATH="$DOCKER_APP_BIN:$PATH"
export PATH
ENV_FILE=../../../../apps/backend/.env
value() { grep "^$1=" "$ENV_FILE" | cut -d= -f2-; }

docker rm -f bw-shopware >/dev/null 2>&1 || true
docker run -d --name bw-shopware -p 127.0.0.1:8480:80 dockware/play:6.7.2.2 >/dev/null
trap 'docker rm -f bw-shopware >/dev/null 2>&1 || true' EXIT
../wait-ready.sh
docker exec bw-shopware bash -lc "cd /var/www/html && composer require 'swag/paypal:^10' --no-interaction >/dev/null 2>&1 && php bin/console plugin:refresh >/dev/null && php bin/console plugin:install --activate SwagPayPal >/dev/null && php bin/console cache:clear >/dev/null"
BW_PAYPAL_CLIENT_ID="$(value WATCHDOG_DEV_PAYPAL_SHOP_CLIENT_ID)" BW_PAYPAL_CLIENT_SECRET="$(value WATCHDOG_DEV_PAYPAL_SHOP_CLIENT_SECRET)" python3 -I configure.py >/dev/null

before=$(docker exec bw-shopware mysql -h127.0.0.1 -uroot -proot shopware -N -e 'SELECT COUNT(*) FROM `order`' 2>/dev/null)
out=$(BW_LOCAL_TIMEOUT_TEST=1 BW_SKIP_HANDLE_PAYMENT=1 BW_SW_CURRENCY=USD python3 -I order.py | sed -n 's/^BW_ORDER=//p')
field() { python3 -c 'import json,sys; print(json.loads(sys.argv[1])[sys.argv[2]])' "$out" "$1"; }
docker network disconnect bridge bw-shopware
handler=$(docker exec -e BW_ORDER_ID="$(field id)" -e BW_SESSION_TOKEN="$(field context_token)" -e BW_ACCESS_KEY="$(field access_key)" bw-shopware sh -c 'curl -sS --max-time 50 -X POST http://127.0.0.1/store-api/handle-payment -H "Host: shop-sw.example.com" -H "Content-Type: application/json" -H "sw-access-key: $BW_ACCESS_KEY" -H "sw-context-token: $BW_SESSION_TOKEN" -d "{\"orderId\":\"$BW_ORDER_ID\",\"finishUrl\":\"http://shop-sw.example.com/checkout/finish\",\"errorUrl\":\"http://shop-sw.example.com/account/order/edit/$BW_ORDER_ID\"}"' || true)
redirect=$(python3 -c 'import json,sys; print(json.loads(sys.argv[1]).get("redirectUrl", ""))' "$handler")
redirect_path=$(python3 -c 'import sys,urllib.parse; print(urllib.parse.urlsplit(sys.argv[1]).path)' "$redirect")
transaction_state=$(docker exec bw-shopware mysql -h127.0.0.1 -uroot -proot shopware -N -e "SELECT s.technical_name FROM order_transaction t JOIN state_machine_state s ON s.id=t.state_id WHERE LOWER(HEX(t.order_id))='$(field id)' ORDER BY t.created_at DESC LIMIT 1" 2>/dev/null)
after=$(docker exec bw-shopware mysql -h127.0.0.1 -uroot -proot shopware -N -e 'SELECT COUNT(*) FROM `order`' 2>/dev/null)
python3 - "$before" "$after" "$redirect_path" "$transaction_state" <<'PY'
import json,sys
before,after=map(int,sys.argv[1:3]); path,state=sys.argv[3:5]
ok=after==before+1 and path.startswith('/account/order/edit/') and state not in ('paid','paid_partially','authorized')
print('PayPal Sandbox unreachable:', {'order_created':after==before+1,'redirected_to_payment_error':path.startswith('/account/order/edit/'),'transaction_state':state})
print('RESULT', 'PASS' if ok else 'FAIL')
sys.exit(0 if ok else 1)
PY
