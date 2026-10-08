<?php

namespace App\Http\Dto\Stores;

use App\Models\Store;
use Illuminate\Support\Collection;

class StoreDto
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Store $store): array
    {
        return [
            'id' => $store->id,
            'name' => $store->name,
            'base_url' => $store->base_url,
            'platform' => $store->platform,
            'timezone' => $store->timezone,
            'locale' => $store->locale,
            'default_currency' => $store->default_currency,
            'status' => $store->status,
            'verified_at' => $store->verified_at?->toJSON(),
            'browser_enabled' => $store->browser_enabled,
            'telemetry_enabled' => $store->telemetry_enabled,
            'config_version' => $store->config_version,
            'coverage' => null,
            'last_successful_check_at' => null,
            'active_incident_count' => 0,
        ];
    }

    /**
     * @param Collection<int, Store> $stores
     * @return array<int, array<string, mixed>>
     */
    public function collection(Collection $stores): array
    {
        return $stores
            ->map(fn (Store $store): array => $this->toArray($store))
            ->all();
    }
}
