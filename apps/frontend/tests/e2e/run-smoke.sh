#!/bin/sh
# Starts the backend and the Vite dev server, seeds a smoke user, and runs tests/e2e/smoke.mjs in the
# Playwright image (built from apps/browser-worker). Screenshots land in apps/frontend/tests/e2e/.out.
set -eu

cd "$(dirname "$0")/../../../.."
ROOT="$(pwd)"
DOCKER_APP_BIN=/Applications/Docker.app/Contents/Resources/bin
[ -d "$DOCKER_APP_BIN" ] && PATH="$DOCKER_APP_BIN:$PATH"
[ -d "$HOME/.nvm/versions/node/v24.21.0/bin" ] && PATH="$HOME/.nvm/versions/node/v24.21.0/bin:$PATH"
PHP="${PHP:-/opt/homebrew/opt/php@8.4/bin/php}"
OUT="$ROOT/apps/frontend/tests/e2e/.out"
mkdir -p "$OUT"
chmod 777 "$OUT"

(cd apps/backend && env DB_HOST=127.0.0.1 "$PHP" artisan tinker --execute='
use App\Models\{User,Tenant,Membership,Store,Integration};
if (! User::query()->where("email", "smoke@example.test")->exists()) {
    $u = User::query()->create(["name" => "Smoke Owner", "email" => "smoke@example.test", "password_hash" => Hash::make("smoke-password-1234"), "locale" => "ru"]);
    $t = Tenant::query()->create(["name" => "Smoke GmbH", "timezone" => "Europe/Berlin"]);
    Membership::query()->create(["tenant_id" => $t->id, "user_id" => $u->id, "role" => "owner"]);
    $s = Store::query()->create(["tenant_id" => $t->id, "name" => "Kaffeerösterei Lindner", "base_url" => "https://kaffee-lindner.example", "timezone" => "Europe/Berlin", "default_currency" => "EUR"]);
    Integration::query()->create(["tenant_id" => $t->id, "store_id" => $s->id, "provider" => "woocommerce", "install_id" => Str::uuid(), "mode" => "live", "source_authority" => "store_reported", "status" => "active", "capabilities" => [], "connector_version" => "0.6.0", "health" => ["freshness" => ["state" => "fresh"]], "last_heartbeat_at" => now()]);
}' >/dev/null)

(cd apps/backend && DB_HOST=127.0.0.1 CACHE_STORE=database SANCTUM_STATEFUL_DOMAINS=host.docker.internal:5173,localhost:5173 "$PHP" artisan serve --host=127.0.0.1 --port=8000 > "$OUT/backend.log" 2>&1) &
(cd apps/frontend && BW_ALLOWED_HOSTS=host.docker.internal npx vite --host 0.0.0.0 > "$OUT/vite.log" 2>&1) &
stop_servers() {
    for port in 8000 5173; do
        lsof -ti "tcp:${port}" -sTCP:LISTEN 2>/dev/null | xargs kill 2>/dev/null || true
    done
}
trap stop_servers EXIT
sleep 5

docker build -q -t bw-browser-worker:smoke apps/browser-worker >/dev/null
docker run --rm --add-host host.docker.internal:host-gateway \
    -v "$ROOT/apps/frontend/tests/e2e/smoke.mjs:/app/smoke.mjs:ro" -v "$OUT:/shots" \
    -e BW_SMOKE_SCREENSHOTS=/shots -e BW_APP_URL=http://host.docker.internal:5173 \
    -e BW_SMOKE_EMAIL=smoke@example.test -e BW_SMOKE_PASSWORD=smoke-password-1234 \
    bw-browser-worker:smoke node smoke.mjs
