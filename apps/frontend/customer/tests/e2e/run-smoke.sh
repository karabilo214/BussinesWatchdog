#!/bin/sh
# Starts the backend and the Vite dev server, seeds a smoke user, and runs tests/e2e/smoke.mjs in the
# Playwright image (built from apps/browser-worker). Screenshots land in apps/frontend/customer/tests/e2e/.out.
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

(cd apps/backend && env DB_HOST=127.0.0.1 "$PHP" artisan tinker --execute='
use App\Models\{User,Tenant,Membership,Store,Integration};
$extra = DB::table("stores")->where("name", "Smoke Neuer Shop")->pluck("id");
DB::table("pairing_codes")->whereIn("store_id", $extra)->delete();
DB::table("store_verifications")->whereIn("store_id", $extra)->delete();
DB::table("stores")->whereIn("id", $extra)->delete();
if (! User::query()->where("email", "smoke@example.test")->exists()) {
    $u = User::query()->create(["name" => "Smoke Owner", "email" => "smoke@example.test", "password_hash" => Hash::make("smoke-password-1234"), "locale" => "ru"]);
    $t = Tenant::query()->create(["name" => "Smoke GmbH", "timezone" => "Europe/Berlin"]);
    Membership::query()->create(["tenant_id" => $t->id, "user_id" => $u->id, "role" => "owner"]);
    $s = Store::query()->create(["tenant_id" => $t->id, "name" => "Kaffeerösterei Lindner", "base_url" => "https://kaffee-lindner.example", "timezone" => "Europe/Berlin", "default_currency" => "EUR"]);
    Integration::query()->create(["tenant_id" => $t->id, "store_id" => $s->id, "provider" => "woocommerce", "install_id" => Str::uuid(), "mode" => "live", "source_authority" => "store_reported", "status" => "active", "capabilities" => [], "connector_version" => "0.6.0", "health" => ["freshness" => ["state" => "fresh"]], "last_heartbeat_at" => now()]);
}
$lindner = Store::query()->where("name", "Kaffeerösterei Lindner")->firstOrFail();
$smokeSignals = DB::table("signals")->where("tenant_id", $lindner->tenant_id)->where("dedupe_key", "like", "smoke-%")->pluck("id");
$smokeIncidents = DB::table("incident_signals")->whereIn("signal_id", $smokeSignals)->pluck("incident_id");
DB::table("incident_activity")->whereIn("incident_id", $smokeIncidents)->delete();
DB::table("suppressions")->whereIn("incident_id", $smokeIncidents)->delete();
DB::table("incident_signals")->whereIn("incident_id", $smokeIncidents)->delete();
DB::table("incidents")->whereIn("id", $smokeIncidents)->delete();
DB::table("signals")->whereIn("id", $smokeSignals)->delete();
$incident = App\Models\Incident::query()->create(["tenant_id" => $lindner->tenant_id, "store_id" => $lindner->id, "family" => "checkout_payment", "component" => "payment_method:stripe", "fingerprint" => "smoke-".Str::uuid(), "state" => "open", "severity" => "warning", "title_code" => "CHECKOUT_PAYMENTS_FAILING", "first_seen_at" => now()->subMinutes(20), "last_seen_at" => now()->subMinutes(5), "first_bad_at" => now()->subMinutes(20), "last_good_at" => now()->subHours(2), "revision" => 1, "created_at" => now()->subMinutes(20), "updated_at" => now()->subMinutes(5)]);
$signal = App\Models\Signal::query()->forceCreate(["id" => Str::uuid7(), "tenant_id" => $lindner->tenant_id, "store_id" => $lindner->id, "signal_type" => "payment_attempts", "family" => "checkout_payment", "component" => "CHECKOUT_PAYMENTS_FAILING", "dedupe_key" => "smoke-".Str::uuid(), "severity" => "warning", "confidence" => "observed", "rule_version" => "1", "config_version" => 1, "evidence" => ["payment_method" => "stripe", "failure_streak" => 4, "threshold" => 3, "failure_classes" => ["declined" => 4], "last_success_at" => now()->subHours(2)->toJSON()], "data_quality" => ["source" => "store_reported_checkout"], "detected_at" => now()->subMinutes(5)]);
App\Models\IncidentSignal::query()->create(["tenant_id" => $lindner->tenant_id, "store_id" => $lindner->id, "incident_id" => $incident->id, "signal_id" => $signal->id, "association_reason" => "smoke", "linked_at" => now()]);
App\Models\IncidentActivity::query()->create(["tenant_id" => $lindner->tenant_id, "store_id" => $lindner->id, "incident_id" => $incident->id, "kind" => "created", "actor_id" => null, "incident_revision" => 1, "sanitized_data" => [], "created_at" => now()->subMinutes(20)]);' >/dev/null)

(cd apps/backend && DB_HOST=127.0.0.1 CACHE_STORE=database SANCTUM_STATEFUL_DOMAINS=host.docker.internal:5173,localhost:5173 "$PHP" artisan serve --host=127.0.0.1 --port=8000 > "$OUT/backend.log" 2>&1) &
(cd apps/frontend/customer && BW_ALLOWED_HOSTS=host.docker.internal npx vite --host 0.0.0.0 > "$OUT/vite.log" 2>&1) &
stop_servers() {
    for port in 8000 5173; do
        lsof -ti "tcp:${port}" -sTCP:LISTEN 2>/dev/null | xargs kill 2>/dev/null || true
    done
}
trap stop_servers EXIT
sleep 5

docker build -q -t bw-browser-worker:smoke apps/browser-worker >/dev/null
docker run --rm --add-host host.docker.internal:host-gateway \
    -v "$ROOT/apps/frontend/customer/tests/e2e/smoke.mjs:/app/smoke.mjs:ro" -v "$OUT:/shots" \
    -e BW_SMOKE_SCREENSHOTS=/shots -e BW_APP_URL=http://host.docker.internal:5173 \
    -e BW_SMOKE_EMAIL=smoke@example.test -e BW_SMOKE_PASSWORD=smoke-password-1234 \
    bw-browser-worker:smoke node smoke.mjs
