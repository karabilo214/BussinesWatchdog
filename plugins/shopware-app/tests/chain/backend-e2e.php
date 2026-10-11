<?php

use App\Models\Integration;
use App\Models\IntegrationCredential;
use App\Models\Membership;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

$action = getenv('BW_E2E_ACTION');
$out = [];

if ($action === 'setup') {
    $tenant = Tenant::query()->create(['name' => 'Shopware chain E2E', 'timezone' => 'Europe/Berlin']);
    $user = User::query()->create(['name' => 'E2E', 'email' => 'e2e-'.Str::random(10).'@example.test', 'password_hash' => Hash::make(Str::random(32)), 'locale' => 'de']);
    Membership::query()->create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => 'owner']);
    $store = Store::query()->create(['tenant_id' => $tenant->id, 'name' => 'Shopware chain', 'base_url' => getenv('BW_E2E_BASE_URL'), 'timezone' => 'Europe/Berlin', 'default_currency' => 'EUR', 'platform' => 'shopware']);
    $connector = app(\App\Support\Providers\Shopware\ShopwareConnector::class);
    $refused = 'not_refused';

    try {
        $connector->connect($store, (string) getenv('BW_E2E_ACCESS_KEY'), (string) getenv('BW_E2E_SECRET'), false, $user->id);
    } catch (\App\Support\Account\AccountRejected $rejected) {
        $refused = $rejected->reasonCode;
    }

    $integration = $connector->connect($store, (string) getenv('BW_E2E_ACCESS_KEY'), (string) getenv('BW_E2E_SECRET'), true, $user->id);
    $out = ['store_id' => $store->id, 'without_acknowledgement' => $refused, 'status' => $integration->status, 'store_verified' => $store->refresh()->verified_at !== null];
}

if ($action === 'sync') {
    $storeId = getenv('BW_E2E_STORE_ID');
    $integration = Integration::query()->where('store_id', $storeId)->where('provider', 'shopware')->firstOrFail();
    $result = app(\App\Support\Providers\Shopware\ShopwareSync::class)->run($integration, (string) getenv('BW_E2E_MODE'));

    for ($i = 0; $i < 6; $i++) {
        app(\App\Support\Outbox\DomainOutboxDispatcher::class)->dispatchDue(limit: 200, leaseSeconds: 60);
    }

    app(\App\Support\Integrations\ConnectorFreshness::class)->check($integration->id);
    $out = [
        'sync' => $result,
        'freshness' => $integration->fresh()->health['freshness']['state'] ?? null,
        'inbox' => \App\Models\EventInbox::query()->where('store_id', $storeId)->get()->groupBy('status')->map->count()->all(),
        'orders' => \App\Models\Order::query()->where('store_id', $storeId)->get()->mapWithKeys(fn ($o) => [$o->display_number => implode('|', [$o->status, $o->currency, $o->total_minor, $o->gateway, $o->financial_support, $o->metadata['refund_state'] ?? '-', $o->paid_marked_at ? 'paid' : 'unpaid', $o->deleted_at ? 'deleted' : 'live', 'rev'.$o->source_revision])])->all(),
        'pii_in_inbox' => str_contains(json_encode(\App\Models\EventInbox::query()->where('store_id', $storeId)->pluck('payload')), '@example.test'),
    ];
}

if ($action === 'paypal-setup') {
    $tenant = Tenant::query()->create(['name' => 'Shopware PayPal chain E2E', 'timezone' => 'Europe/Berlin']);
    $user = User::query()->create(['name' => 'E2E', 'email' => 'e2e-'.Str::random(10).'@example.test', 'password_hash' => Hash::make(Str::random(32)), 'locale' => 'de']);
    Membership::query()->create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => 'owner']);
    $store = Store::query()->create(['tenant_id' => $tenant->id, 'name' => 'Shopware PayPal chain', 'base_url' => getenv('BW_E2E_BASE_URL'), 'timezone' => 'Europe/Berlin', 'default_currency' => 'USD', 'platform' => 'shopware']);
    $shopware = app(\App\Support\Providers\Shopware\ShopwareConnector::class)->connect($store, (string) getenv('BW_E2E_ACCESS_KEY'), (string) getenv('BW_E2E_SECRET'), true, $user->id);
    $paypal = app(\App\Support\Providers\PayPal\PayPalConnector::class)->connect($store, 'sandbox', (string) env('WATCHDOG_DEV_PAYPAL_CLIENT_ID'), (string) env('WATCHDOG_DEV_PAYPAL_CLIENT_SECRET'), null, true, $user->id);
    $out = ['store_id' => $store->id, 'shopware' => $shopware->status, 'paypal' => $paypal->mode];
}

if ($action === 'paypal-check') {
    $storeId = getenv('BW_E2E_STORE_ID');
    $drain = function (): void {
        for ($i = 0; $i < 6; $i++) {
            app(\App\Support\Outbox\DomainOutboxDispatcher::class)->dispatchDue(limit: 200, leaseSeconds: 60);
        }
    };
    $shopware = Integration::query()->where('store_id', $storeId)->where('provider', 'shopware')->firstOrFail();
    $paypal = Integration::query()->where('store_id', $storeId)->where('provider', 'paypal')->firstOrFail();
    $sync = app(\App\Support\Providers\Shopware\ShopwareSync::class)->run($shopware);
    $drain();
    $pointers = app(\App\Support\Providers\PayPal\PayPalSync::class)->syncPointers($paypal);
    $drain();
    $orders = [];

    foreach (json_decode((string) getenv('BW_E2E_NUMBERS'), true) as $label => $number) {
        $order = \App\Models\Order::query()->where('store_id', $storeId)->where('display_number', $number)->firstOrFail();
        app(\App\Support\Reconciliation\OrderReconciliationService::class)->evaluate($order->refresh());
        $orders[$label] = [
            'order' => implode('|', [$order->status, $order->currency, $order->total_minor, $order->gateway, $order->mode, $order->financial_support, $order->metadata['refund_state'] ?? '-', $order->transaction_ref ? 'ref' : '-']),
            'links' => \App\Models\PaymentAllocation::query()->where('order_id', $order->id)->whereNull('revoked_at')->get()->map(fn ($a) => $a->strategy.':'.$a->amount_minor)->all(),
            'findings' => \App\Models\ReconciliationFinding::query()->where('order_id', $order->id)->get()->map(fn ($f) => $f->rule_code.':'.$f->status.':'.$f->reason_code.':'.($f->refund_actual_minor ?? '-'))->all(),
        ];
    }

    $out = ['shopware_sync' => $sync, 'pointer_sync' => $pointers, 'orders' => $orders];
}

if ($action === 'cleanup') {
    Integration::query()->where('store_id', getenv('BW_E2E_STORE_ID'))->update(['status' => Integration::STATUS_REVOKED]);
    IntegrationCredential::query()->where('store_id', getenv('BW_E2E_STORE_ID'))->update(['status' => IntegrationCredential::STATUS_REVOKED]);
    $out = ['revoked' => true];
}

echo 'BW_E2E_JSON='.json_encode($out).PHP_EOL;
