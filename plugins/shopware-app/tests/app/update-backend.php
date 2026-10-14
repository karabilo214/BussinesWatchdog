<?php

use App\Models\AuditLog;
use App\Models\Integration;
use App\Models\IntegrationCredential;
use App\Models\Store;
use App\Support\Providers\Shopware\ShopwareApp;
use App\Support\Providers\Shopware\ShopwareConnector;

$action = getenv('BW_E2E_ACTION');
$out = [];
$store = getenv('BW_E2E_STORE_ID') ? Store::query()->find(getenv('BW_E2E_STORE_ID')) : null;

if ($action === 'old-archive') {
    $integration = Integration::query()->where('store_id', $store->id)->where('provider', 'shopware')->latest('created_at')->firstOrFail();
    $secret = app(ShopwareConnector::class)->secretJson($integration, IntegrationCredential::KIND_SHOPWARE_APP)['manifest_secret'];
    $archive = new ReflectionMethod(ShopwareApp::class, 'archive');
    $archive->setAccessible(true);
    file_put_contents((string) getenv('BW_E2E_ZIP'), $archive->invoke(app(ShopwareApp::class), $integration, $secret, '1.0.0', false));
    $out = ['integration_id' => $integration->id, 'version' => '1.0.0'];
}

if ($action === 'update-archive') {
    $package = app(ShopwareApp::class)->updatePackage($store, null);
    file_put_contents((string) getenv('BW_E2E_ZIP'), $package['archive']);
    $out = ['integration_id' => $package['integration']->id, 'version' => ShopwareApp::APP_VERSION];
}

if ($action === 'state') {
    for ($i = 0; $i < 6; $i++) {
        app(\App\Support\Outbox\DomainOutboxDispatcher::class)->dispatchDue(limit: 200, leaseSeconds: 60);
    }

    $integrations = Integration::query()->where('store_id', $store->id)->where('provider', 'shopware')->get();
    $integration = $integrations->sortByDesc('created_at')->first();
    $out = [
        'integrations' => $integrations->count(),
        'integration_id' => $integration?->id,
        'status' => $integration?->status,
        'app_update' => $integration ? app(\App\Http\Dto\Integrations\IntegrationDto::class)->toArray($integration)['app_update'] : null,
        'last_webhook' => $integration?->health['webhook']['last_type'] ?? null,
        'active_credentials' => IntegrationCredential::query()->where('integration_id', $integration?->id)->where('status', 'active')->pluck('kind')->sort()->values()->all(),
        'registrations' => AuditLog::query()->where('store_id', $store->id)->get()->filter(fn ($log) => ($log->changes['stage'] ?? null) === 'registered')->count(),
        'orders' => \App\Models\Order::query()->where('store_id', $store->id)->pluck('display_number')->all(),
    ];
}

echo 'BW_E2E_JSON='.json_encode($out).PHP_EOL;
