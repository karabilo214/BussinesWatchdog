<?php

use App\Models\Integration;
use App\Models\IntegrationCredential;
use App\Models\Membership;
use App\Models\PairingCode;
use App\Models\ShopwareAppInstallation;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Providers\Shopware\ShopwareApp;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

$action = getenv('BW_E2E_ACTION');
$out = [];

if ($action === 'universal-package') {
    $tenant = Tenant::query()->create(['name' => 'Universal app E2E', 'timezone' => 'Europe/Berlin']);
    $user = User::query()->create(['name' => 'E2E', 'email' => 'e2e-'.Str::random(10).'@example.test', 'password_hash' => Hash::make(Str::random(32)), 'locale' => 'de']);
    Membership::query()->create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => 'owner']);
    $store = Store::query()->create(['tenant_id' => $tenant->id, 'name' => 'Universal app', 'base_url' => getenv('BW_E2E_BASE_URL'), 'timezone' => 'Europe/Berlin', 'default_currency' => 'EUR', 'platform' => 'shopware']);
    $other = Store::query()->create(['tenant_id' => $tenant->id, 'name' => 'Other domain', 'base_url' => 'https://other.example.test', 'timezone' => 'Europe/Berlin', 'default_currency' => 'EUR', 'platform' => 'shopware']);
    file_put_contents((string) getenv('BW_E2E_ZIP'), app(ShopwareApp::class)->universalPackage()['archive']);
    $out = ['store_id' => $store->id, 'other_store_id' => $other->id, 'user_id' => $user->id];
}

if ($action === 'code') {
    $store = Store::query()->findOrFail(getenv('BW_E2E_STORE_ID'));
    $code = 'bwpc_'.Str::lower(Str::random(40));
    PairingCode::query()->create(['tenant_id' => $store->tenant_id, 'store_id' => $store->id, 'code_hash' => hash('sha256', $code), 'created_by' => getenv('BW_E2E_USER_ID'), 'expires_at' => now()->addMinutes(15), 'created_at' => now()]);
    $out = ['code' => $code];
}

if ($action === 'state') {
    $storeId = getenv('BW_E2E_STORE_ID');
    $integration = Integration::query()->where('store_id', $storeId)->where('provider', 'shopware')->latest('created_at')->first();
    for ($i = 0; $i < 6; $i++) {
        app(\App\Support\Outbox\DomainOutboxDispatcher::class)->dispatchDue(limit: 200, leaseSeconds: 60);
    }
    $out = [
        'installations' => ShopwareAppInstallation::query()->get()->map(fn ($i) => ['status' => $i->status, 'shop_url' => $i->shop_url, 'version' => $i->app_version, 'bound_to' => $i->integration_id])->all(),
        'integration' => $integration?->status,
        'connection' => $integration?->capabilities['connection'] ?? null,
        'active_credentials' => $integration === null ? [] : IntegrationCredential::query()->where('integration_id', $integration->id)->where('status', 'active')->pluck('kind')->all(),
        'orders' => \App\Models\Order::query()->where('store_id', $storeId)->get()->mapWithKeys(fn ($o) => [$o->display_number => implode('|', [$o->status, $o->paid_marked_at ? 'paid' : 'unpaid'])])->all(),
        'last_webhook' => $integration?->health['webhook']['last_type'] ?? null,
    ];
}

echo 'BW_E2E_JSON='.json_encode($out).PHP_EOL;
