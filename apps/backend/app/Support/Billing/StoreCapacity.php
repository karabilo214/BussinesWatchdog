<?php

namespace App\Support\Billing;

use App\Models\Store;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * When the plan allows fewer stores than the tenant has (spec/panels/CLIENT-PANELS.md §5, OWNER-PANEL.md §5), the
 * owner chooses which stores stay monitored; the others are paused for scheduled checks, never deleted, and their
 * history and setup stay available. Without a choice no store is picked for the owner: the tenant gets
 * `capacity_choice_required` and its scheduled browser checks pause until the owner decides.
 */
class StoreCapacity
{
    public function __construct(private readonly EntitlementCalculator $calculator) {}

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
            $this->stores($tenantId)->whereKey($storeIds)->update(['capacity_paused_at' => null]);
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
            $this->stores($tenantId)->whereNotNull('capacity_paused_at')->update(['capacity_paused_at' => null]);

            return;
        }

        if ($retained !== null && $retained !== []) {
            $existing = $this->stores($tenantId)->whereKey($retained)->pluck('id')->all();

            if ($existing !== [] && count($existing) <= $limit) {
                $this->retain($tenantId, $existing);
            }
        }
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
