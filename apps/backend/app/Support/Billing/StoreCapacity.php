<?php

namespace App\Support\Billing;

use App\Models\Integration;
use App\Models\Store;
use App\Models\Tenant;
use App\Support\Privacy\OrganizationDeletion;
use App\Support\Stores\StoreCatchUp;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * When the plan allows fewer stores than the tenant has (spec/panels/CLIENT-PANELS.md §5, OWNER-PANEL.md §5), the
 * owner chooses which stores stay monitored; the others are fully paused (ADR 0037 point 7): no ingestion, provider
 * sync, reconciliation, freshness or browser checks, never deleted, history and setup stay available. Resuming catches
 * the paused period up through the connectors' audit window. Without a choice no store is picked for the owner: the
 * tenant gets `capacity_choice_required` and its scheduled browser checks pause until the owner decides.
 */
class StoreCapacity
{
    public const ERROR_PAUSED = 'store_capacity_paused';

    public const DEVIATION_NOTICE_DAYS = 30;

    public function __construct(
        private readonly EntitlementCalculator $calculator,
        private readonly StoreCatchUp $catchUp,
    ) {}

    /** Paused above the plan limit (ADR 0037), or its organization is being deleted (ADR 0069): the same full stop. */
    public static function paused(Integration $integration): bool
    {
        return Store::query()
            ->where('tenant_id', $integration->tenant_id)
            ->whereKey($integration->store_id)
            ->where(fn ($query) => $query->whereNotNull('capacity_paused_at')->orWhereIn('tenant_id', self::deletingTenantIds()))
            ->exists();
    }

    /**
     * @return Builder<Store>
     */
    public static function pausedStoreIds(): Builder
    {
        return Store::query()
            ->where(fn ($query) => $query->whereNotNull('capacity_paused_at')->orWhereIn('tenant_id', self::deletingTenantIds()))
            ->select('id');
    }

    /**
     * @return Builder<Tenant>
     */
    private static function deletingTenantIds(): Builder
    {
        return Tenant::query()->whereIn('status', OrganizationDeletion::DELETING_STATUSES)->select('id');
    }

    /**
     * @return array{limit: ?int, stores: int, paused: int, choice_required: bool}
     */
    public function state(string $tenantId): array
    {
        $limit = $this->limit($tenantId);
        $stores = $this->stores($tenantId)->get(['id', 'capacity_paused_at']);
        $running = $stores->whereNull('capacity_paused_at')->count();

        return [
            'limit' => $limit,
            'stores' => $stores->count(),
            'paused' => $stores->count() - $running,
            'choice_required' => $limit !== null && $running > $limit,
        ];
    }

    public function choiceRequired(string $tenantId): bool
    {
        return $this->state($tenantId)['choice_required'];
    }

    /**
     * @param  list<string>  $storeIds
     */
    public function retain(string $tenantId, array $storeIds): void
    {
        $limit = $this->limit($tenantId);
        $storeIds = array_values(array_unique($storeIds));
        $known = $this->stores($tenantId)->whereKey($storeIds)->count();

        if ($storeIds === [] || $known !== count($storeIds) || ($limit !== null && count($storeIds) > $limit)) {
            throw new BillingCheckoutRejected('retained_stores_invalid');
        }

        DB::transaction(function () use ($tenantId, $storeIds): void {
            $this->stores($tenantId)->whereKeyNot($storeIds)->whereNull('capacity_paused_at')->update(['capacity_paused_at' => Carbon::now()]);
            $this->resume($tenantId, $this->stores($tenantId)->whereKey($storeIds));
        });
    }

    /**
     * Brings the stores in line with the current limit: everything runs when the limit allows it, the owner's choice
     * (from a plan change) is applied when it fits, otherwise an earlier choice stays as it is.
     *
     * @param  list<string>|null  $retained
     */
    public function settle(string $tenantId, ?array $retained = null): void
    {
        $this->calculator->forget();
        $limit = $this->limit($tenantId);

        if ($limit === null || $this->stores($tenantId)->count() <= $limit) {
            DB::transaction(fn () => $this->resume($tenantId, $this->stores($tenantId)));

            return;
        }

        if ($retained !== null && $retained !== []) {
            $existing = $this->stores($tenantId)->whereKey($retained)->pluck('id')->all();

            if ($existing !== [] && count($existing) <= $limit) {
                $this->retain($tenantId, $existing);
            }
        }
    }

    /**
     * @param  Builder<Store>  $stores
     */
    private function resume(string $tenantId, Builder $stores): void
    {
        $resumed = (clone $stores)->whereNotNull('capacity_paused_at')->lockForUpdate()->get(['id', 'capacity_paused_at']);

        if ($resumed->isEmpty()) {
            return;
        }

        $now = Carbon::now();

        foreach ($resumed as $store) {
            $this->stores($tenantId)->whereKey($store->id)->update([
                'capacity_paused_at' => null,
                'capacity_last_paused_from' => $store->capacity_paused_at,
                'capacity_last_resumed_at' => $now,
            ]);
            $this->catchUp->resumed($tenantId, $store->id, $store->capacity_paused_at);
        }
    }

    /**
     * The last capacity pause, for a notice that data of that period may deviate (ADR 0037 point 7): shown for a while
     * after the resume, because what the connectors could not catch up is the owner's choice, not a fault.
     *
     * @return array{from: string, until: string}|null
     */
    public static function recentPause(Store $store): ?array
    {
        if ($store->capacity_last_paused_from === null || $store->capacity_last_resumed_at === null
            || $store->capacity_last_resumed_at->lessThan(Carbon::now()->subDays(self::DEVIATION_NOTICE_DAYS))) {
            return null;
        }

        return ['from' => $store->capacity_last_paused_from->toJSON(), 'until' => $store->capacity_last_resumed_at->toJSON()];
    }

    private function limit(string $tenantId): ?int
    {
        return $this->calculator->forTenant($tenantId)->limit(FeatureCatalog::MAX_ACTIVE_STORES);
    }

    /**
     * @return Builder<Store>
     */
    private function stores(string $tenantId): Builder
    {
        return Store::query()->where('tenant_id', $tenantId)->whereNotIn('status', ['deleted', 'deleting']);
    }
}
