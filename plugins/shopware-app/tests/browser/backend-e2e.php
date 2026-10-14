<?php

use App\Models\Artifact;
use App\Models\BrowserWorker;
use App\Models\CheckAttempt;
use App\Models\CheckRun;
use App\Models\CheckScenario;
use App\Models\Store;
use App\Models\Tenant;
use App\Support\Browser\ScenarioDefinition;
use App\Support\Security\Keyring;
use Illuminate\Support\Str;

$action = getenv('BW_E2E_ACTION');
$out = [];
$origin = (string) getenv('BW_E2E_ORIGIN');

if ($action === 'setup') {
    $tenant = Tenant::query()->create(['name' => 'Shopware browser E2E', 'timezone' => 'Europe/Berlin']);
    $store = Store::query()->create(['tenant_id' => $tenant->id, 'name' => 'Shopware browser E2E', 'base_url' => 'https://shopware-browser-e2e.example.test', 'timezone' => 'Europe/Berlin', 'default_currency' => 'EUR', 'platform' => 'shopware']);
    $store->forceFill(['status' => 'active', 'verified_at' => now(), 'browser_enabled' => true])->save();
    $scenario = CheckScenario::query()->create([
        'tenant_id' => $tenant->id,
        'store_id' => $store->id,
        'name' => 'Payment form',
        'mode' => CheckScenario::MODE_PAYMENT_FORM,
        'version' => 1,
        'enabled' => true,
        'adapter_version' => ScenarioDefinition::SHOPWARE_ADAPTER_VERSION,
        'checkout_access' => CheckScenario::ACCESS_STOP_BEFORE_SIGN_IN,
        'definition' => ['product_url' => $origin.'/', 'cart_url' => $origin.'/checkout/cart', 'checkout_url' => $origin.'/checkout/confirm'],
        'interval_seconds' => 900,
        'next_due_at' => now()->addDay(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $token = 'bwwk_'.bin2hex(random_bytes(32));
    BrowserWorker::query()->create(['name' => 'sw-e2e-'.Str::random(8), 'token_hash' => hash('sha256', $token), 'status' => 'active', 'created_at' => now()]);
    $out = ['store_id' => $store->id, 'scenario_id' => $scenario->id, 'worker_token' => $token];
}

if ($action === 'run') {
    $scenario = CheckScenario::query()->findOrFail(getenv('BW_E2E_SCENARIO_ID'));
    $access = (string) getenv('BW_E2E_ACCESS');
    $signIn = null;

    if ($access === CheckScenario::ACCESS_TEST_CUSTOMER) {
        $signIn = app(Keyring::class)->encrypt(json_encode(['email' => getenv('BW_E2E_EMAIL'), 'password' => getenv('BW_E2E_PASSWORD')], JSON_THROW_ON_ERROR));
    }

    $scenario->forceFill([
        'checkout_access' => $access,
        'sign_in_ciphertext' => $signIn['ciphertext'] ?? null,
        'sign_in_key_version' => $signIn['key_version'] ?? null,
        'definition' => array_merge($scenario->definition, ['product_url' => getenv('BW_E2E_PRODUCT_URL')]),
        'version' => $scenario->version + 1,
    ])->save();
    CheckRun::query()->where('store_id', $scenario->store_id)->whereIn('status', ['queued', 'running'])->update(['status' => 'cancelled', 'finished_at' => now()]);
    CheckAttempt::query()->where('store_id', $scenario->store_id)->where('status', 'running')->update(['status' => 'cancelled', 'finished_at' => now()]);
    $snapshot = app(ScenarioDefinition::class)->snapshot($scenario, Store::query()->findOrFail($scenario->store_id));
    $snapshot['store_origin'] = $origin;
    $snapshot['network_policy']['allowed_origins'] = [$origin];
    $run = CheckRun::query()->create([
        'tenant_id' => $scenario->tenant_id,
        'store_id' => $scenario->store_id,
        'scenario_id' => $scenario->id,
        'scenario_version' => $scenario->version,
        'trigger' => 'manual',
        'dedupe_key' => 'e2e:'.Str::uuid(),
        'status' => 'queued',
        'config_snapshot' => $snapshot,
        'scheduled_at' => now(),
        'next_attempt_at' => now(),
        'created_at' => now(),
    ]);
    $out = ['run_id' => $run->id];
}

if ($action === 'status') {
    $run = CheckRun::query()->findOrFail(getenv('BW_E2E_RUN_ID'));
    $attempts = CheckAttempt::query()->where('run_id', $run->id)->orderBy('attempt_number')->with('steps')->get();
    $password = (string) getenv('BW_E2E_PASSWORD');
    $out = [
        'run_status' => $run->status,
        'run_error' => $run->error_code,
        'secret_in_results' => $password !== '' && (str_contains(json_encode($attempts->toArray()), $password) || str_contains(json_encode($run->config_snapshot), $password)),
        'attempts' => $attempts->map(fn (CheckAttempt $a) => [
            'status' => $a->status,
            'error_code' => $a->error_code,
            'steps' => $a->steps->map(fn ($s) => $s->step_code.':'.$s->status.($s->error_code ? ':'.$s->error_code : '').(isset($s->assertions[0]['detail_code']) ? '('.$s->assertions[0]['detail_code'].')' : ''))->all(),
            'artifacts' => Artifact::query()->where('attempt_id', $a->id)->where('state', 'ready')->count(),
            'errors' => collect($a->sanitized_error['relevant_errors'] ?? [])->map(fn ($e) => ($e['type'] ?? '?').':'.($e['message_code'] ?? $e['status'] ?? '').':'.($e['path'] ?? ''))->take(10)->all(),
        ])->all(),
    ];
}

echo 'BW_E2E_JSON='.json_encode($out).PHP_EOL;
