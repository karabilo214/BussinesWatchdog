<?php

use App\Models\BrowserWorker;
use App\Models\CheckAttempt;
use App\Models\CheckoutObservationWindow;
use App\Models\CheckRun;
use App\Models\CheckScenario;
use App\Models\Incident;
use App\Models\Integration;
use App\Models\ObservedPaymentAttempt;
use App\Models\Store;
use App\Support\Browser\ScenarioDefinition;
use App\Support\Checkout\CheckoutObservationEvaluator;
use App\Support\Providers\Shopware\ShopwareSync;
use App\Support\Stores\StoreCoverage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

$action = getenv('BW_E2E_ACTION');
$out = [];
$store = getenv('BW_E2E_STORE_ID') ? Store::query()->find(getenv('BW_E2E_STORE_ID')) : null;
$origin = 'http://localhost';

if ($action === 'scenario') {
    $store->forceFill(['status' => 'active', 'verified_at' => $store->verified_at ?? now(), 'browser_enabled' => true])->save();
    CheckScenario::query()->updateOrCreate(['store_id' => $store->id, 'mode' => CheckScenario::MODE_PAYMENT_FORM], [
        'tenant_id' => $store->tenant_id, 'name' => 'Payment form', 'version' => 1, 'enabled' => true,
        'adapter_version' => ScenarioDefinition::SHOPWARE_ADAPTER_VERSION, 'checkout_access' => getenv('BW_E2E_ACCESS'),
        'definition' => ['product_url' => getenv('BW_E2E_PRODUCT_URL'), 'cart_url' => $origin.'/checkout/cart', 'checkout_url' => $origin.'/checkout/confirm'],
        'interval_seconds' => 900, 'next_due_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    Cache::flush();
    $out = ['access' => getenv('BW_E2E_ACCESS')];
}

if ($action === 'layout') {
    $settings = $store->settings ?? [];
    $settings['checkout_layout'] = json_decode((string) getenv('BW_E2E_LAYOUT'), true);
    $store->forceFill(['settings' => $settings])->save();
    $out = ['layout' => $settings['checkout_layout']];
}

if ($action === 'collect' || $action === 'state') {
    $integration = Integration::query()->where('store_id', $store->id)->where('provider', 'shopware')->latest('created_at')->first();
    Cache::flush();
    $sync = app(ShopwareSync::class)->run($integration);
    $out = ['sync' => $sync['status'], 'observation' => $integration->refresh()->health['observation'] ?? null];
}

if ($action === 'state') {
    app(CheckoutObservationEvaluator::class)->evaluateAll();
    $out = [
        'counts' => collect(CheckoutObservationWindow::COUNTERS)->mapWithKeys(fn ($c) => [$c => (int) CheckoutObservationWindow::query()->where('store_id', $store->id)->sum($c)])->all(),
        'incidents' => Incident::query()->where('store_id', $store->id)->where('family', CheckoutObservationEvaluator::FAMILY)->orderBy('created_at')->get()->map(fn ($i) => $i->title_code.':'.$i->state)->all(),
        'attempts' => ObservedPaymentAttempt::query()->where('store_id', $store->id)->count(),
        'coverage' => app(StoreCoverage::class)->forStores(collect([$store->refresh()]))[$store->id]['coverage']['checkout_script']['state'],
        'integration' => $integration?->status,
        'observation' => $out['observation'],
    ];
}

if ($action === 'check') {
    $scenario = CheckScenario::query()->where('store_id', $store->id)->firstOrFail();
    $token = 'bwwk_'.bin2hex(random_bytes(32));
    BrowserWorker::query()->create(['name' => 'obs-e2e-'.Str::random(8), 'token_hash' => hash('sha256', $token), 'status' => 'active', 'created_at' => now()]);
    $snapshot = app(ScenarioDefinition::class)->snapshot($scenario, $store);
    $snapshot['store_origin'] = $origin;
    $snapshot['network_policy']['allowed_origins'] = [$origin];
    $run = CheckRun::query()->create([
        'tenant_id' => $store->tenant_id, 'store_id' => $store->id, 'scenario_id' => $scenario->id, 'scenario_version' => $scenario->version,
        'trigger' => 'manual', 'dedupe_key' => 'e2e:'.Str::uuid(), 'status' => 'queued', 'config_snapshot' => $snapshot,
        'scheduled_at' => now(), 'next_attempt_at' => now(), 'created_at' => now(),
    ]);
    $out = ['run_id' => $run->id, 'worker_token' => $token, 'expect_script' => $snapshot['expect_checkout_script'], 'quiet' => $snapshot['network_policy']['quiet_paths']];
}

if ($action === 'check-status') {
    $run = CheckRun::query()->findOrFail(getenv('BW_E2E_RUN_ID'));
    $attempt = CheckAttempt::query()->where('run_id', $run->id)->with('steps')->first();
    $out = [
        'run_status' => $run->status,
        'steps' => $attempt?->steps->map(fn ($s) => $s->step_code.':'.$s->status)->all() ?? [],
        'script' => $attempt?->steps->firstWhere('step_code', 'payment_form')?->assertions ?? [],
        'errors' => collect($attempt?->sanitized_error['relevant_errors'] ?? [])->map(fn ($e) => ($e['type'] ?? '?').':'.($e['origin'] ?? ''))->all(),
    ];
}

echo 'BW_E2E_JSON='.json_encode($out).PHP_EOL;
