<?php

namespace App\Support\Integrations;

use App\Models\Integration;

/**
 * Whether money of an order can be checked against an independent provider. When the order's gateway is known to
 * belong to one provider (Stripe gateways, PayPal Payments), only that provider counts: a store with Stripe connected
 * cannot confirm PayPal money. Unknown gateways keep the store-wide rule.
 */
class ProviderCoverage
{
    public const REASON_NOT_CONNECTED = 'provider_not_connected';

    /** The store did not report the payment's test/live mode and the provider runs in test mode (ADR 0024). */
    public const REASON_MODE_NEEDS_PLUGIN = 'payment_mode_needs_plugin';

    public static function providerForGateway(?string $gateway): ?string
    {
        return match (true) {
            $gateway === null || $gateway === '' => null,
            $gateway === 'stripe' || str_starts_with($gateway, 'stripe_') => 'stripe',
            str_starts_with($gateway, 'ppcp-') || $gateway === 'ppcp' => 'paypal',
            default => null,
        };
    }

    /**
     * Whether every active provider that could hold the order's money runs in test mode. Orders whose store did not
     * report a mode are taken as live, so against a test-mode provider they cannot be checked.
     */
    public function onlyTestMode(string $tenantId, string $storeId, ?string $gateway = null): bool
    {
        $provider = self::providerForGateway($gateway);
        $modes = Integration::query()
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where('source_authority', Integration::SOURCE_INDEPENDENT_PROVIDER)
            ->where('status', Integration::STATUS_ACTIVE)
            ->when($provider !== null, fn ($query) => $query->where('provider', $provider))
            ->distinct()
            ->pluck('mode')
            ->all();

        return $modes === ['test'];
    }

    public function isConnected(string $tenantId, string $storeId, ?string $gateway = null): bool
    {
        $provider = self::providerForGateway($gateway);

        return Integration::query()
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where('source_authority', Integration::SOURCE_INDEPENDENT_PROVIDER)
            ->where('status', Integration::STATUS_ACTIVE)
            ->when($provider !== null, fn ($query) => $query->where('provider', $provider))
            ->exists();
    }
}
