<?php

use App\Models\Integration;
use App\Support\Providers\WooCommerce\WooCommerceApiWebhooks;

$action = getenv('BW_E2E_ACTION');
$out = [];
$integration = getenv('BW_E2E_STORE_ID') ? Integration::query()->where('store_id', getenv('BW_E2E_STORE_ID'))->where('provider', 'woocommerce_api')->first() : null;

if ($action === 'webhook-setup') {
    $out = app(WooCommerceApiWebhooks::class)->setUp($integration, null);
}

if ($action === 'webhook-state') {
    for ($i = 0; $i < 6; $i++) {
        app(\App\Support\Outbox\DomainOutboxDispatcher::class)->dispatchDue(limit: 200, leaseSeconds: 60);
    }
    $integration->refresh();
    $out = [
        'webhook' => $integration->health['webhook'] ?? null,
        'last_sync' => $integration->health['sync']['last_run_at'] ?? null,
        'orders' => \App\Models\Order::query()->where('store_id', $integration->store_id)->get()->mapWithKeys(fn ($o) => [$o->display_number => $o->status])->all(),
    ];
}

echo 'BW_E2E_JSON='.json_encode($out).PHP_EOL;
