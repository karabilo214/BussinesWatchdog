<?php

use App\Models\Incident;
use App\Models\Integration;
use App\Models\Membership;
use App\Models\ObservedPaymentAttempt;
use App\Models\PaymentAttemptWindow;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Providers\WooCommerce\WooCommerceApiConnector;
use App\Support\Providers\WooCommerce\WooCommerceApiSync;
use App\Support\Stores\StoreCoverage;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

$action = getenv('BW_E2E_ACTION');
$out = [];

if ($action === 'setup') {
    $tenant = Tenant::query()->create(['name' => 'REST API attempts E2E', 'timezone' => 'Europe/Berlin']);
    $user = User::query()->create(['name' => 'E2E', 'email' => 'e2e-'.Str::random(10).'@example.test', 'password_hash' => Hash::make(Str::random(32)), 'locale' => 'de']);
    Membership::query()->create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => 'owner']);
    $store = Store::query()->create(['tenant_id' => $tenant->id, 'name' => 'REST API attempts', 'base_url' => getenv('BW_E2E_BASE_URL'), 'timezone' => 'Europe/Berlin', 'default_currency' => 'EUR']);
    $integration = app(WooCommerceApiConnector::class)->connect($store, (string) getenv('BW_E2E_READ_KEY'), (string) getenv('BW_E2E_READ_SECRET'), $user->id);
    $out = ['store_id' => $store->id, 'status' => $integration->status];
}

if ($action === 'sync') {
    $store = Store::query()->findOrFail(getenv('BW_E2E_STORE_ID'));
    $integration = Integration::query()->where('store_id', $store->id)->where('provider', 'woocommerce_api')->firstOrFail();
    $sync = app(WooCommerceApiSync::class)->run($integration, WooCommerceApiSync::MODE_DELTA);
    $out = [
        'sync' => $sync['status'],
        'attempts' => ObservedPaymentAttempt::query()->where('store_id', $store->id)->orderByRaw('CAST(order_ref AS BIGINT)')->get()->mapWithKeys(fn ($a) => [$a->order_ref => $a->payment_method.':'.$a->outcome.($a->failure_class ? ':'.$a->failure_class : '')])->all(),
        'windows' => collect(PaymentAttemptWindow::OUTCOME_COUNTERS)->mapWithKeys(fn ($c) => [$c => (int) PaymentAttemptWindow::query()->where('store_id', $store->id)->sum($c)])->all(),
        'incidents' => Incident::query()->where('store_id', $store->id)->orderBy('created_at')->get()->map(fn ($i) => $i->title_code.':'.$i->state)->all(),
        'coverage' => app(StoreCoverage::class)->forStores(collect([$store->refresh()]))[$store->id]['coverage']['payment_attempts']['state'],
        'pii' => str_contains(json_encode(ObservedPaymentAttempt::query()->where('store_id', $store->id)->get()->toArray()), '@'),
    ];
}

echo 'BW_E2E_JSON='.json_encode($out).PHP_EOL;
