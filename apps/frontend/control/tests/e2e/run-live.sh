#!/bin/sh
# Live checks of the control panel through the Vite dev server and the real backend with a staff owner who has an
# authenticator: `run-live.sh login` (sign-in, own cookies, sign-out), `run-live.sh plans` (create, price, publish to
# the Paddle sandbox and retire a plan) or `run-live.sh discounts` (create a promo code there); the last two need the sandbox keys. Screenshots land in tests/e2e/.out.
set -eu
SCRIPT="${1:-login}"

cd "$(dirname "$0")/../../../../.."
ROOT="$(pwd)"
DOCKER_APP_BIN=/Applications/Docker.app/Contents/Resources/bin
[ -d "$DOCKER_APP_BIN" ] && PATH="$DOCKER_APP_BIN:$PATH"
[ -d "$HOME/.nvm/versions/node/v24.21.0/bin" ] && PATH="$HOME/.nvm/versions/node/v24.21.0/bin:$PATH"
PHP="${PHP:-/opt/homebrew/opt/php@8.4/bin/php}"
OUT="$ROOT/apps/frontend/control/tests/e2e/.out"
mkdir -p "$OUT"
chmod 777 "$OUT"
EMAIL="control-live-$(date +%s)@example.test"
PASSWORD="control-live-password-1234"

(cd apps/backend && env DB_HOST=127.0.0.1 "$PHP" artisan migrate --force >/dev/null)
SECRET=$(cd apps/backend && env DB_HOST=127.0.0.1 BW_EMAIL="$EMAIL" BW_PASSWORD="$PASSWORD" "$PHP" artisan tinker --execute='
$u = App\Models\User::query()->create(["name" => "Control Live", "email" => getenv("BW_EMAIL"), "password_hash" => Hash::make(getenv("BW_PASSWORD")), "locale" => "ru"]);
$mfa = app(App\Support\Account\MfaService::class);
$secret = $mfa->beginSetup($u)["secret"];
$totp = new App\Support\Account\Totp;
$mfa->confirm($u, $totp->codeAt($secret, $totp->step(time()) - 1));
App\Models\PlatformStaff::query()->create(["user_id" => $u->id, "role" => "platform_owner", "state" => "active"]);
echo $secret;' | tail -1)

(cd apps/backend && DB_HOST=127.0.0.1 CACHE_STORE=database "$PHP" artisan serve --host=127.0.0.1 --port=8000 > "$OUT/backend.log" 2>&1) &
(cd apps/frontend/control && BW_ALLOWED_HOSTS=host.docker.internal npx vite --host 0.0.0.0 > "$OUT/vite.log" 2>&1) &
stop_servers() {
    for port in 8000 5174; do
        lsof -ti "tcp:${port}" -sTCP:LISTEN 2>/dev/null | xargs kill 2>/dev/null || true
    done
}
trap stop_servers EXIT
sleep 5

docker build -q -t bw-browser-worker:smoke apps/browser-worker >/dev/null
docker run --rm --add-host host.docker.internal:host-gateway \
    -v "$ROOT/apps/frontend/control/tests/e2e/$SCRIPT.mjs:/app/live.mjs:ro" -v "$OUT:/shots" \
    -e BW_SHOTS=/shots -e BW_CONTROL_URL=http://host.docker.internal:5174 \
    -e BW_EMAIL="$EMAIL" -e BW_PASSWORD="$PASSWORD" -e BW_TOTP_SECRET="$SECRET" \
    bw-browser-worker:smoke node live.mjs
