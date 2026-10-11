<?php

namespace App\Support\Integrations;

use App\Models\Integration;
use App\Models\Store;

/**
 * The connectors that report a store's orders. A store has one at a time (ADR 0024): the WooCommerce plugin, a
 * WooCommerce REST API key or a Shopware integration key.
 */
final class OrderSources
{
    public const PROVIDERS = ['woocommerce', 'woocommerce_api', 'shopware'];

    /** Order sources read by polling an API, so freshness follows the last successful sync, not a heartbeat. */
    public const POLLED = ['woocommerce_api', 'shopware'];

    public static function active(Store $store): ?string
    {
        return Integration::query()
            ->where('tenant_id', $store->tenant_id)
            ->where('store_id', $store->id)
            ->whereIn('provider', self::PROVIDERS)
            ->whereIn('status', [Integration::STATUS_PENDING, Integration::STATUS_ACTIVE, Integration::STATUS_DEGRADED])
            ->value('provider');
    }
}
