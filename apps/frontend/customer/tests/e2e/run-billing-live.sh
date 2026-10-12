#!/bin/sh
# Live check of the subscription page against the Paddle sandbox (needs internet and the sandbox keys in
# apps/backend/.env): a fresh verified owner buys the Start plan with one extra store through Paddle.js with Paddle's
# test card; the page must show the payment as confirmed only after the backend has read the subscription from Paddle.
# With BW_PROMO=<code> the buyer applies that promo code first (create one with control `run-live.sh discounts`).
set -eu

cd "$(dirname "$0")/../../../../.."
ROOT="$(pwd)"
DOCKER_APP_BIN=/Applications/Docker.app/Contents/Resources/bin
[ -d "$DOCKER_APP_BIN" ] && PATH="$DOCKER_APP_BIN:$PATH"
[ -d "$HOME/.nvm/versions/node/v24.21.0/bin" ] && PATH="$HOME/.nvm/versions/node/v24.21.0/bin:$PATH"
PHP="${PHP:-/opt/homebrew/opt/php@8.4/bin/php}"
OUT="$ROOT/apps/frontend/customer/tests/e2e/.out"
mkdir -p "$OUT"
chmod 777 "$OUT"
EMAIL="billing-live-$(date +%s)@example.test"
PASSWORD="billing-live-password-1234"

(cd apps/backend && env DB_HOST=127.0.0.1 "$PHP" artisan migrate --force >/dev/null)
(cd apps/backend && env DB_HOST=127.0.0.1 "$PHP" artisan billing:seed-placeholder-plans --test-prices >/dev/null)
(cd apps/backend && env DB_HOST=127.0.0.1 BW_EMAIL="$EMAIL" BW_PASSWORD="$PASSWORD" "$PHP" artisan tinker --execute='
use App\Models\{User,Tenant,Membership,Store};
$u = User::query()->create(["name" => "Billing Live", "email" => getenv("BW_EMAIL"), "password_hash" => Hash::make(getenv("BW_PASSWORD")), "locale" => "ru"]);
$u->forceFill(["email_verified_at" => now()])->save();
$t = Tenant::query()->create(["name" => "Billing Live GmbH", "timezone" => "Europe/Berlin"]);
Membership::query()->create(["tenant_id" => $t->id, "user_id" => $u->id, "role" => "owner"]);
Store::query()->create(["tenant_id" => $t->id, "name" => "Billing Live Shop", "base_url" => "https://billing-live-".Str::lower(Str::random(8)).".example", "timezone" => "Europe/Berlin", "default_currency" => "EUR"]);' >/dev/null)

(cd apps/backend && DB_HOST=127.0.0.1 CACHE_STORE=database SANCTUM_STATEFUL_DOMAINS=host.docker.internal:5173,localhost:5173 "$PHP" artisan serve --host=127.0.0.1 --port=8000 > "$OUT/billing-backend.log" 2>&1) &
(cd apps/frontend/customer && BW_ALLOWED_HOSTS=host.docker.internal npx vite --host 0.0.0.0 > "$OUT/billing-vite.log" 2>&1) &
(cd apps/backend && while true; do DB_HOST=127.0.0.1 CACHE_STORE=database "$PHP" artisan billing:paddle-events >/dev/null 2>&1; DB_HOST=127.0.0.1 CACHE_STORE=database "$PHP" artisan billing:process-events >/dev/null 2>&1; sleep 5; done) &
EVENTS_LOOP=$!
stop_servers() {
    kill "$EVENTS_LOOP" 2>/dev/null || true
    for port in 8000 5173; do
        lsof -ti "tcp:${port}" -sTCP:LISTEN 2>/dev/null | xargs kill 2>/dev/null || true
    done
}
trap stop_servers EXIT
sleep 5

docker build -q -t bw-browser-worker:smoke apps/browser-worker >/dev/null
docker run --rm --add-host host.docker.internal:host-gateway \
    -v "$ROOT/apps/frontend/customer/tests/e2e/billing-live.mjs:/app/billing-live.mjs:ro" -v "$OUT:/shots" \
    -e BW_SMOKE_SCREENSHOTS=/shots -e BW_APP_URL=http://host.docker.internal:5173 \
    -e BW_SMOKE_EMAIL="$EMAIL" -e BW_SMOKE_PASSWORD="$PASSWORD" -e BW_PROMO="${BW_PROMO:-}" -e BW_EXPECT_AFTER="${BW_EXPECT_AFTER:-22,40}" \
    bw-browser-worker:smoke node billing-live.mjs
