<?php

namespace App\Support\Integrations;

use App\Models\Integration;

class ProviderCoverage
{
    public const REASON_NOT_CONNECTED = 'provider_not_connected';

    public function isConnected(string $tenantId, string $storeId): bool
    {
        return Integration::query()
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where('source_authority', Integration::SOURCE_INDEPENDENT_PROVIDER)
            ->where('status', Integration::STATUS_ACTIVE)
            ->exists();
    }
}
