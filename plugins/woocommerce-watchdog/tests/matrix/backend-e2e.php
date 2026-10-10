<?php

use App\Models\Integration;
use App\Models\IntegrationCredential;
use App\Models\Membership;
use App\Models\PairingCode;
use App\Models\Store;
use App\Models\StoreVerification;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Integrations\IntegrationCredentialService;
use App\Support\Stores\StoreVerificationService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

$action = getenv('BW_E2E_ACTION');
$out = [];

if ($action === 'setup') {
    $baseUrl = getenv('BW_E2E_BASE_URL');
    $tenant = Tenant::query()->create(['name' => 'E2E '.$baseUrl, 'timezone' => 'Europe/Kyiv']);
    $user = User::query()->create(['name' => 'E2E', 'email' => 'e2e-'.Str::random(10).'@example.test', 'password_hash' => Hash::make(Str::random(32)), 'locale' => 'en']);
    Membership::query()->create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => 'owner']);
    $store = Store::query()->create(['tenant_id' => $tenant->id, 'name' => 'E2E', 'base_url' => $baseUrl, 'timezone' => 'Europe/Kyiv', 'default_currency' => 'EUR']);
    $code = 'bwpc_'.Str::random(32);
    PairingCode::query()->create(['tenant_id' => $tenant->id, 'store_id' => $store->id, 'code_hash' => hash('sha256', $code), 'created_by' => $user->id, 'expires_at' => now()->addMinutes(15), 'created_at' => now()]);
    $out = ['store_id' => $store->id, 'pairing_code' => $code];
} elseif ($action === 'start-verification') {
    $store = Store::query()->findOrFail(getenv('BW_E2E_STORE_ID'));
    $verification = app(StoreVerificationService::class)->start($store, StoreVerification::METHOD_PLUGIN_CHALLENGE);
    $out = ['verification_id' => $verification->id, 'challenge' => app(StoreVerificationService::class)->challenge($verification->id)];
} elseif ($action === 'request-rotation') {
    $integration = Integration::query()->where('store_id', getenv('BW_E2E_STORE_ID'))->firstOrFail();
    app(IntegrationCredentialService::class)->requestRotation($integration, null);
    $out = ['integration_id' => $integration->id];
} elseif ($action === 'status') {
    $integration = Integration::query()->where('store_id', getenv('BW_E2E_STORE_ID'))->first();
    $out = [
        'integration_provider' => $integration?->provider,
        'last_heartbeat_at' => $integration?->last_heartbeat_at?->toJSON(),
        'credentials' => $integration ? IntegrationCredential::query()->where('integration_id', $integration->id)->orderBy('created_at')->pluck('status')->all() : [],
    ];
}

if ($action === 'validate-events') {
    $events = json_decode((string) file_get_contents(getenv('BW_E2E_EVENTS_FILE')), true);
    $schema = app(\App\Support\Ingest\EventSchemaValidator::class);
    $semantic = app(\App\Support\Ingest\EventPayloadValidator::class);
    $types = [];
    $failures = [];

    foreach ($events as $index => $event) {
        $raw = json_decode(json_encode($event));
        $result = $semantic->validate($event, $raw);
        $types[] = $event['type'].(isset($event['data']['status']) ? ':'.$event['data']['status'] : '');

        if (! $result->valid) {
            $error = $schema->firstError($raw);
            $failures[] = ['index' => $index, 'type' => $event['type'], 'code' => $result->errorCode, 'schema' => $error ? $error->keyword().' at '.implode('/', $error->data()->fullPath()) : null];
        }
    }

    $out = ['count' => count($events), 'types' => $types, 'failures' => $failures];
}

if ($action === 'projection-status') {
    $storeId = getenv('BW_E2E_STORE_ID');
    \Illuminate\Support\Facades\Artisan::call('outbox:dispatch', ['--limit' => 100]);
    \Illuminate\Support\Facades\Artisan::call('outbox:dispatch', ['--limit' => 100]);
    $out = [
        'inbox' => \App\Models\EventInbox::query()->where('store_id', $storeId)->get()->groupBy('status')->map->count()->all(),
        'inbox_types' => \App\Models\EventInbox::query()->where('store_id', $storeId)->pluck('event_type')->countBy()->all(),
        'orders' => \App\Models\Order::query()->where('store_id', $storeId)->orderBy('created_at')->get(['currency', 'total_minor', 'status', 'deleted_at', 'financial_support'])->map(fn ($o) => [$o->currency, (string) $o->total_minor, $o->status, $o->deleted_at !== null, $o->financial_support])->all(),
        'refunds' => \App\Models\Refund::query()->where('store_id', $storeId)->get(['amount_minor', 'status'])->map(fn ($r) => [(string) $r->amount_minor, $r->status])->all(),
    ];
}

if ($action === 'attempt-windows') {
    $storeId = getenv('BW_E2E_STORE_ID');
    \Illuminate\Support\Facades\Artisan::call('outbox:dispatch', ['--limit' => 100]);
    \Illuminate\Support\Facades\Artisan::call('outbox:dispatch', ['--limit' => 100]);
    $totals = [];

    foreach (\App\Models\PaymentAttemptWindow::query()->where('store_id', $storeId)->get() as $window) {
        foreach (\App\Models\PaymentAttemptWindow::OUTCOME_COUNTERS as $counter) {
            if ($window->{$counter} > 0) {
                $key = $window->payment_method.':'.$counter;
                $totals[$key] = ($totals[$key] ?? 0) + $window->{$counter};
            }
        }
    }

    ksort($totals);
    $out = [
        'inbox' => \App\Models\EventInbox::query()->where('store_id', $storeId)->get()->groupBy('status')->map->count()->all(),
        'totals' => $totals,
    ];
}

if ($action === 'browser-setup') {
    $tenant = Tenant::query()->create(['name' => 'Browser E2E', 'timezone' => 'Europe/Kyiv']);
    $store = Store::query()->create(['tenant_id' => $tenant->id, 'name' => 'Browser E2E', 'base_url' => getenv('BW_E2E_BASE_URL'), 'timezone' => 'Europe/Kyiv', 'default_currency' => 'EUR']);
    $store->forceFill(['status' => 'active', 'verified_at' => now(), 'browser_enabled' => true])->save();
    $scenario = \App\Models\CheckScenario::query()->create([
        'tenant_id' => $tenant->id,
        'store_id' => $store->id,
        'name' => 'Payment form',
        'mode' => 'payment_form',
        'version' => 1,
        'enabled' => true,
        'adapter_version' => \App\Support\Browser\ScenarioDefinition::ADAPTER_VERSION,
        'definition' => ['product_url' => getenv('BW_E2E_PRODUCT_URL'), 'cart_url' => getenv('BW_E2E_CART_URL'), 'checkout_url' => getenv('BW_E2E_CHECKOUT_URL')],
        'interval_seconds' => 900,
        'next_due_at' => now()->addDay(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $token = 'bwwk_'.bin2hex(random_bytes(32));
    \App\Models\BrowserWorker::query()->create(['name' => 'e2e-'.Str::random(8), 'token_hash' => hash('sha256', $token), 'status' => 'active', 'created_at' => now()]);
    $user = User::query()->create(['name' => 'Browser E2E', 'email' => 'browser-e2e-'.Str::random(10).'@example.test', 'password_hash' => Hash::make(Str::random(32)), 'locale' => 'en']);
    Membership::query()->create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => 'owner']);
    $code = 'bwpc_'.Str::random(32);
    PairingCode::query()->create(['tenant_id' => $tenant->id, 'store_id' => $store->id, 'code_hash' => hash('sha256', $code), 'created_by' => $user->id, 'expires_at' => now()->addMinutes(15), 'created_at' => now()]);
    $out = ['store_id' => $store->id, 'scenario_id' => $scenario->id, 'worker_token' => $token, 'pairing_code' => $code];
}

if ($action === 'browser-run') {
    $scenario = \App\Models\CheckScenario::query()->findOrFail(getenv('BW_E2E_SCENARIO_ID'));
    $definition = $scenario->definition;

    if (getenv('BW_E2E_CHECKOUT_URL')) {
        $definition['checkout_url'] = getenv('BW_E2E_CHECKOUT_URL');
        $scenario->forceFill(['definition' => $definition, 'version' => $scenario->version + 1])->save();
    }

    \App\Models\CheckRun::query()
        ->whereIn('store_id', Store::query()->where('name', 'Browser E2E')->select('id'))
        ->whereIn('status', ['queued', 'running'])
        ->update(['status' => 'cancelled', 'finished_at' => now()]);
    \App\Models\CheckAttempt::query()
        ->whereIn('store_id', Store::query()->where('name', 'Browser E2E')->select('id'))
        ->where('status', 'running')
        ->update(['status' => 'cancelled', 'finished_at' => now()]);
    $run = \App\Models\CheckRun::query()->create([
        'tenant_id' => $scenario->tenant_id,
        'store_id' => $scenario->store_id,
        'scenario_id' => $scenario->id,
        'scenario_version' => $scenario->version,
        'trigger' => 'manual',
        'dedupe_key' => 'e2e:'.Str::uuid(),
        'status' => 'queued',
        'config_snapshot' => (function () use ($scenario): array {
            $snapshot = app(\App\Support\Browser\ScenarioDefinition::class)->snapshot($scenario, Store::query()->findOrFail($scenario->store_id));
            $local = app(\App\Support\Browser\ScenarioDefinition::class)->origin($snapshot['product_url']);
            $snapshot['store_origin'] = $local;
            $snapshot['network_policy']['allowed_origins'] = [$local];

            return $snapshot;
        })(),
        'scheduled_at' => now(),
        'next_attempt_at' => now(),
        'created_at' => now(),
    ]);
    $out = ['run_id' => $run->id];
}

if ($action === 'browser-status') {
    $run = \App\Models\CheckRun::query()->findOrFail(getenv('BW_E2E_RUN_ID'));
    $attempts = \App\Models\CheckAttempt::query()->where('run_id', $run->id)->orderBy('attempt_number')->with('steps')->get();
    $out = [
        'run_status' => $run->status,
        'run_error' => $run->error_code,
        'attempts' => $attempts->map(fn ($a) => [
            'status' => $a->status,
            'error_code' => $a->error_code,
            'steps' => $a->steps->map(fn ($s) => $s->step_code.':'.$s->status.($s->error_code ? ':'.$s->error_code : '').(isset($s->assertions[0]['detail_code']) ? '('.$s->assertions[0]['detail_code'].')' : ''))->all(),
            'artifacts' => \App\Models\Artifact::query()->where('attempt_id', $a->id)->where('state', 'ready')->get()->map(function ($artifact) {
                $disk = \Illuminate\Support\Facades\Storage::disk('artifacts');
                $bytes = $disk->exists($artifact->object_key) ? $disk->get($artifact->object_key) : '';

                if ($bytes !== '' && getenv('BW_E2E_ARTIFACT_DIR')) {
                    file_put_contents(getenv('BW_E2E_ARTIFACT_DIR').'/'.$artifact->id.'.jpg', $bytes);
                }

                return $artifact->content_type.':'.($bytes !== '' && hash('sha256', $bytes) === $artifact->sha256 ? 'stored' : 'missing');
            })->all(),
            'errors' => collect($a->sanitized_error['relevant_errors'] ?? [])->map(fn ($e) => ($e['type'] ?? '?').':'.($e['message_code'] ?? $e['status'] ?? '').':'.($e['path'] ?? ''))->take(10)->all(),
        ])->all(),
    ];
}

if ($action === 'stripe-chain-setup') {
    $tenant = Tenant::query()->create(['name' => 'Stripe chain E2E', 'timezone' => 'Europe/Berlin']);
    $user = User::query()->create(['name' => 'E2E', 'email' => 'e2e-'.Str::random(10).'@example.test', 'password_hash' => Hash::make(Str::random(32)), 'locale' => 'en']);
    Membership::query()->create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => 'owner']);
    $store = Store::query()->create(['tenant_id' => $tenant->id, 'name' => 'Stripe chain', 'base_url' => getenv('BW_E2E_BASE_URL'), 'timezone' => 'Europe/Berlin', 'default_currency' => 'EUR']);
    $store->forceFill(['verified_at' => now(), 'status' => 'active'])->save();
    $code = 'bwpc_'.Str::lower(Str::random(40));
    PairingCode::query()->create(['tenant_id' => $tenant->id, 'store_id' => $store->id, 'code_hash' => hash('sha256', $code), 'created_by' => $user->id, 'expires_at' => now()->addMinutes(15), 'created_at' => now()]);
    $stripe = app(\App\Support\Providers\Stripe\StripeConnector::class)->connect($store, (string) env('WATCHDOG_DEV_STRIPE_RESTRICTED_API_KEY'), null, null, $user->id);
    $out = ['store_id' => $store->id, 'pairing_code' => $code, 'stripe_integration_id' => $stripe->id, 'stripe_mode' => $stripe->mode];
}

if ($action === 'stripe-chain-check') {
    $storeId = getenv('BW_E2E_STORE_ID');
    $wooOrderId = getenv('BW_E2E_ORDER_ID');
    $drain = function (): void {
        for ($i = 0; $i < 6; $i++) {
            app(\App\Support\Outbox\DomainOutboxDispatcher::class)->dispatchDue(limit: 200, leaseSeconds: 60);
        }
    };
    $drain();
    $stripe = Integration::query()->where('store_id', $storeId)->where('provider', 'stripe')->firstOrFail();
    $sync = app(\App\Support\Providers\Stripe\StripeSync::class)->run($stripe, 'audit');
    $drain();
    $order = \App\Models\Order::query()->where('store_id', $storeId)->where('external_id', $wooOrderId)->first();
    $findings = [];

    if ($order !== null) {
        app(\App\Support\Reconciliation\OrderReconciliationService::class)->evaluate($order);
        $findings = \App\Models\ReconciliationFinding::query()->where('order_id', $order->id)->get()->map(fn ($f) => $f->rule_code.':'.$f->status)->all();
    }

    $out = [
        'sync' => $sync,
        'inbox' => \App\Models\EventInbox::query()->where('store_id', $storeId)->get()->groupBy('status')->map->count()->all(),
        'order' => $order ? ['mode' => $order->mode, 'transaction_ref_prefix' => substr((string) $order->transaction_ref, 0, 3), 'total_minor' => (string) $order->total_minor] : null,
        'store_refunds' => \App\Models\Refund::query()->where('store_id', $storeId)->get()->map(fn ($r) => ['amount' => (string) $r->amount_minor, 'provider_ref_prefix' => substr((string) $r->provider_ref, 0, 3)])->all(),
        'capture_links' => $order ? \App\Models\PaymentAllocation::query()->where('order_id', $order->id)->whereNull('revoked_at')->get()->map(fn ($a) => $a->strategy.':'.$a->amount_minor)->all() : [],
        'refund_links' => \App\Models\RefundAllocation::query()->where('store_id', $storeId)->whereNull('revoked_at')->get()->map(fn ($a) => $a->strategy.':'.$a->amount_minor)->all(),
        'findings' => $findings,
    ];
}

if ($action === 'stripe-chain-cleanup') {
    Integration::query()->where('store_id', getenv('BW_E2E_STORE_ID'))->where('provider', 'stripe')->update(['status' => Integration::STATUS_REVOKED]);
    IntegrationCredential::query()->where('store_id', getenv('BW_E2E_STORE_ID'))->whereIn('kind', [IntegrationCredential::KIND_STRIPE_API, IntegrationCredential::KIND_STRIPE_WEBHOOK])->update(['status' => IntegrationCredential::STATUS_REVOKED]);
    $out = ['revoked' => true];
}

echo 'BW_E2E_JSON='.json_encode($out).PHP_EOL;
