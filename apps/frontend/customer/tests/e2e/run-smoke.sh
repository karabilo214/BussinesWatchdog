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

docker compose up -d mailpit >/dev/null 2>&1
curl -s -X DELETE http://127.0.0.1:8025/api/v1/messages >/dev/null || true

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
User::query()->where("email", "smoke@example.test")->update(["email_verified_at" => null, "name" => "Smoke Owner"]);
$smokeGuests = User::query()->where("email", "like", "%@smoke.example.test")->pluck("id");
DB::table("audit_log")->whereIn("actor_user_id", $smokeGuests)->delete();
DB::table("memberships")->whereIn("user_id", $smokeGuests)->delete();
DB::table("sessions")->whereIn("user_id", $smokeGuests)->delete();
DB::table("invitations")->where("tenant_id", $lindner->tenant_id)->delete();
DB::table("password_reset_tokens")->where("email", "like", "%@smoke.example.test")->delete();
User::query()->whereIn("id", $smokeGuests)->delete();
DB::table("notification_deliveries")->where("tenant_id", $lindner->tenant_id)->delete();
DB::table("notification_channel_verifications")->where("tenant_id", $lindner->tenant_id)->delete();
DB::table("notification_channels")->where("tenant_id", $lindner->tenant_id)->delete();
$scope = ["tenant_id" => $lindner->tenant_id, "store_id" => $lindner->id];
foreach (["incident_activity", "suppressions", "incident_signals", "incidents", "signals", "reconciliation_dirty_subjects", "refund_allocations", "payment_allocations", "reconciliation_findings", "reconciliation_runs", "financial_transactions", "payments"] as $table) {
    DB::table($table)->where($scope)->delete();
}
DB::table("orders")->where($scope)->where("display_number", "like", "#SM-%")->delete();
$woo = Integration::query()->where($scope)->where("provider", "woocommerce")->firstOrFail();
$stripe = Integration::query()->firstOrCreate($scope + ["provider" => "stripe"], ["install_id" => Str::uuid(), "mode" => "live", "source_authority" => "independent_provider", "status" => "active", "capabilities" => [], "connector_version" => "1.0.0", "health" => []]);
$smokeOrder = fn (string $number, int $total, string $ref) => App\Models\Order::query()->create($scope + ["integration_id" => $woo->id, "external_id" => (string) Str::uuid(), "display_number" => $number, "source_revision" => 1, "status" => "processing", "gateway" => "stripe", "mode" => "live", "currency" => "EUR", "currency_exponent" => 2, "total_minor" => $total, "payment_expected" => true, "paid_marked_at" => now()->subHours(2), "transaction_ref" => $ref, "financial_support" => "supported", "is_synthetic" => false, "source_created_at" => now()->subHours(2), "source_updated_at" => now()->subHours(2), "current_payload_hash" => hash("sha256", (string) Str::uuid()), "metadata" => [], "created_at" => now(), "updated_at" => now()]);
$missing = $smokeOrder("#SM-15238", 18400, "pi_smoke_missing");
$candidate = $smokeOrder("#SM-2002", 7700, "pi_smoke_other");
$payment = App\Models\Payment::query()->create($scope + ["integration_id" => $stripe->id, "external_id" => (string) Str::uuid(), "intent_ref" => "pi_smoke_unmatched", "charge_ref" => "ch_smoke_unmatched", "mode" => "live", "currency" => "EUR", "currency_exponent" => 2, "status" => "captured", "source_authority" => "independent_provider", "source_updated_at" => now()->subHour(), "current_payload_hash" => hash("sha256", (string) Str::uuid()), "metadata" => [], "created_at" => now()->subHour(), "updated_at" => now()->subHour()]);
App\Models\FinancialTransaction::query()->create($scope + ["integration_id" => $stripe->id, "payment_id" => $payment->id, "external_operation_id" => "ch_smoke_unmatched", "kind" => "capture", "status" => "succeeded", "currency" => "EUR", "currency_exponent" => 2, "amount_minor" => 7700, "occurred_at" => now()->subHour(), "source_authority" => "independent_provider", "operation_hash" => hash("sha256", (string) Str::uuid()), "metadata" => [], "created_at" => now()]);
foreach ([$missing, $candidate] as $smokeOrderToCheck) {
    app(App\Support\Incidents\MoneyIncidentCorrelator::class)->correlate(app(App\Support\Reconciliation\OrderReconciliationService::class)->evaluate($smokeOrderToCheck));
}
$checkStore = Store::query()->firstOrCreate(["tenant_id" => $lindner->tenant_id, "name" => "Smoke Check Shop"], ["base_url" => "https://check-shop.example", "timezone" => "Europe/Berlin", "default_currency" => "EUR"]);
$checkStore->forceFill(["verified_at" => now()->subDay(), "status" => "active", "browser_enabled" => true])->save();
$checkStripe = Integration::query()->where("store_id", $checkStore->id)->where("provider", "stripe")->pluck("id");
foreach ($checkStripe as $stripeId) {
    DB::table("domain_outbox")->whereRaw("payload::text like ?", ["%".$stripeId."%"])->delete();
}
foreach (["financial_transactions", "payments", "provider_object_states", "event_inbox", "integration_credentials"] as $table) {
    DB::table($table)->whereIn("integration_id", $checkStripe)->delete();
}
Integration::query()->whereIn("id", $checkStripe)->delete();
$cs = ["tenant_id" => $checkStore->tenant_id, "store_id" => $checkStore->id];
foreach (["check_steps", "artifacts", "check_attempts", "check_runs", "check_scenarios"] as $table) {
    DB::table($table)->where($cs)->delete();
}
$checkScenario = App\Models\CheckScenario::query()->create($cs + ["name" => "Payment form", "mode" => "payment_form", "version" => 1, "enabled" => false, "adapter_version" => App\Support\Browser\ScenarioDefinition::ADAPTER_VERSION, "definition" => ["product_url" => "https://check-shop.example/product/test/"], "interval_seconds" => 900, "next_due_at" => null, "created_at" => now()->subDay(), "updated_at" => now()->subDay()]);
$worker = App\Models\BrowserWorker::query()->firstOrCreate(["name" => "smoke-worker"], ["token_hash" => hash("sha256", (string) Str::uuid()), "status" => "active", "created_at" => now()]);
$pastRun = App\Models\CheckRun::query()->forceCreate($cs + ["id" => Str::uuid7(), "scenario_id" => $checkScenario->id, "scenario_version" => 1, "trigger" => "scheduled", "dedupe_key" => "smoke-".Str::uuid(), "status" => "failed", "config_snapshot" => [], "scheduled_at" => now()->subHours(3), "started_at" => now()->subHours(3), "finished_at" => now()->subHours(3)->addMinutes(2), "next_attempt_at" => now()->subHours(3), "error_code" => "site_failure", "created_at" => now()->subHours(3)]);
foreach ([1, 2] as $number) {
    $attemptId = (string) Str::uuid7();
    DB::table("check_attempts")->insert($cs + ["id" => $attemptId, "run_id" => $pastRun->id, "attempt_number" => $number, "worker_id" => $worker->id, "fencing_token" => $number, "lease_token_hash" => hash("sha256", $attemptId), "lease_until" => now()->subHours(3), "absolute_deadline_at" => now()->subHours(3), "status" => "failed", "browser_version" => "Chromium 140", "location" => "eu-central", "started_at" => now()->subHours(3), "finished_at" => now()->subHours(3)->addMinute(), "error_code" => "site_failure", "sanitized_error" => json_encode(["redaction_version" => "r1", "relevant_errors" => [["type" => "http_status", "message_code" => "checkout_500"]]])]);
    foreach (["product" => "passed", "add_to_cart" => "passed", "cart" => "passed", "checkout" => "failed"] as $step => $status) {
        DB::table("check_steps")->insert($cs + ["id" => (string) Str::uuid7(), "attempt_id" => $attemptId, "step_index" => array_search($step, ["product", "add_to_cart", "cart", "checkout"]), "step_code" => $step, "status" => $status, "started_at" => now()->subHours(3), "finished_at" => now()->subHours(3), "assertions" => "[]", "network_summary" => "[]", "error_code" => $status === "failed" ? "site_failure" : null]);
    }
}
$incident = App\Models\Incident::query()->create(["tenant_id" => $lindner->tenant_id, "store_id" => $lindner->id, "family" => "checkout_payment", "component" => "payment_method:stripe", "fingerprint" => "smoke-".Str::uuid(), "state" => "open", "severity" => "warning", "title_code" => "CHECKOUT_PAYMENTS_FAILING", "first_seen_at" => now()->subMinutes(20), "last_seen_at" => now()->subMinutes(5), "first_bad_at" => now()->subMinutes(20), "last_good_at" => now()->subHours(2), "revision" => 1, "created_at" => now()->subMinutes(20), "updated_at" => now()->subMinutes(5)]);
$signal = App\Models\Signal::query()->forceCreate(["id" => Str::uuid7(), "tenant_id" => $lindner->tenant_id, "store_id" => $lindner->id, "signal_type" => "payment_attempts", "family" => "checkout_payment", "component" => "CHECKOUT_PAYMENTS_FAILING", "dedupe_key" => "smoke-".Str::uuid(), "severity" => "warning", "confidence" => "observed", "rule_version" => "1", "config_version" => 1, "evidence" => ["payment_method" => "stripe", "failure_streak" => 4, "threshold" => 3, "failure_classes" => ["declined" => 4], "last_success_at" => now()->subHours(2)->toJSON()], "data_quality" => ["source" => "store_reported_checkout"], "detected_at" => now()->subMinutes(5)]);
App\Models\IncidentSignal::query()->create(["tenant_id" => $lindner->tenant_id, "store_id" => $lindner->id, "incident_id" => $incident->id, "signal_id" => $signal->id, "association_reason" => "smoke", "linked_at" => now()]);
App\Models\IncidentActivity::query()->create(["tenant_id" => $lindner->tenant_id, "store_id" => $lindner->id, "incident_id" => $incident->id, "kind" => "created", "actor_id" => null, "incident_revision" => 1, "sanitized_data" => [], "created_at" => now()->subMinutes(20)]);' >/dev/null)

(cd apps/backend && DB_HOST=127.0.0.1 CACHE_STORE=database "$PHP" artisan cache:clear >/dev/null)
"$PHP" -S 127.0.0.1:12111 "$ROOT/apps/frontend/customer/tests/e2e/fake-stripe.php" > "$OUT/fake-stripe.log" 2>&1 &
FAKE_STRIPE=$!
(cd apps/backend && DB_HOST=127.0.0.1 CACHE_STORE=database MAIL_MAILER=smtp MAIL_HOST=127.0.0.1 MAIL_PORT=1025 WATCHDOG_STRIPE_API_BASE=http://127.0.0.1:12111 SANCTUM_STATEFUL_DOMAINS=host.docker.internal:5173,localhost:5173 "$PHP" artisan serve --host=127.0.0.1 --port=8000 > "$OUT/backend.log" 2>&1) &
(cd apps/frontend/customer && BW_ALLOWED_HOSTS=host.docker.internal npx vite --host 0.0.0.0 > "$OUT/vite.log" 2>&1) &
(cd apps/backend && while true; do DB_HOST=127.0.0.1 CACHE_STORE=database MAIL_MAILER=smtp MAIL_HOST=127.0.0.1 MAIL_PORT=1025 "$PHP" artisan notifications:deliver >/dev/null 2>&1; sleep 3; done) &
DELIVER_LOOP=$!
(cd apps/backend && while true; do DB_HOST=127.0.0.1 CACHE_STORE=database "$PHP" artisan outbox:dispatch >/dev/null 2>&1; sleep 2; done) &
OUTBOX_LOOP=$!
stop_servers() {
    kill "$DELIVER_LOOP" 2>/dev/null || true
    kill "$OUTBOX_LOOP" "$FAKE_STRIPE" 2>/dev/null || true
    for port in 8000 5173; do
        lsof -ti "tcp:${port}" -sTCP:LISTEN 2>/dev/null | xargs kill 2>/dev/null || true
    done
}
trap stop_servers EXIT
sleep 5

docker build -q -t bw-browser-worker:smoke apps/browser-worker >/dev/null
docker run --rm --add-host host.docker.internal:host-gateway \
    -v "$ROOT/apps/frontend/customer/tests/e2e/smoke.mjs:/app/smoke.mjs:ro" -v "$OUT:/shots" \
    -e BW_SMOKE_SCREENSHOTS=/shots -e BW_MAILPIT_URL=http://host.docker.internal:8025 -e BW_APP_URL=http://host.docker.internal:5173 \
    -e BW_SMOKE_EMAIL=smoke@example.test -e BW_SMOKE_PASSWORD=smoke-password-1234 \
    bw-browser-worker:smoke node smoke.mjs
