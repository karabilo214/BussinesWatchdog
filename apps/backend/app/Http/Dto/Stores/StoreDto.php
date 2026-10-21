<?php

namespace App\Http\Dto\Stores;

use App\Models\Store;
use App\Support\Billing\StoreCapacity;
use App\Support\Checkout\CheckoutLayout;
use App\Support\Stores\StoreCoverage;
use Illuminate\Support\Collection;

class StoreDto
{
    public function __construct(
        private readonly StoreCoverage $coverage,
    ) {}

    /**
     * @param  array{coverage: array<string, mixed>, last_successful_check_at: ?string, active_incident_count: int}|null  $summary
     * @return array<string, mixed>
     */
    public function toArray(Store $store, ?array $summary = null): array
    {
        $summary ??= $this->coverage->forStores(collect([$store]))[$store->id];

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
            'capacity_paused_at' => $store->capacity_paused_at?->toJSON(),
            'last_capacity_pause' => StoreCapacity::recentPause($store),
            'browser_enabled' => $store->browser_enabled,
            'telemetry_enabled' => $store->telemetry_enabled,
            'config_version' => $store->config_version,
            'checkout_layout' => CheckoutLayout::supports($store) ? ['custom' => (object) CheckoutLayout::custom($store), 'defaults' => CheckoutLayout::DEFAULTS] : null,
            'coverage' => $summary['coverage'],
            'last_successful_check_at' => $summary['last_successful_check_at'],
            'active_incident_count' => $summary['active_incident_count'],
        ];
    }

    /**
     * @param  Collection<int, Store>  $stores
     * @return array<int, array<string, mixed>>
     */
    public function collection(Collection $stores): array
    {
        $summaries = $this->coverage->forStores($stores);

        return $stores
            ->map(fn (Store $store): array => $this->toArray($store, $summaries[$store->id]))
            ->all();
    }
}
