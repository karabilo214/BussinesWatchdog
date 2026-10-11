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

if ($action === 'cleanup') {
    Integration::query()->where('store_id', getenv('BW_E2E_STORE_ID'))->update(['status' => Integration::STATUS_REVOKED]);
    IntegrationCredential::query()->where('store_id', getenv('BW_E2E_STORE_ID'))->update(['status' => IntegrationCredential::STATUS_REVOKED]);
    $out = ['revoked' => true];
}

echo 'BW_E2E_JSON='.json_encode($out).PHP_EOL;
