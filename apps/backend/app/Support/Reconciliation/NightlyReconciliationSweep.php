<?php

namespace App\Support\Reconciliation;

use App\Models\ReconciliationDirtySubject;
use App\Models\ReconciliationFinding;
use App\Models\Store;
use App\Models\Tenant;
use App\Support\Billing\EntitlementCalculator;
use App\Support\Billing\FeatureCatalog;
use App\Support\Integrations\ConnectorFreshness;
use App\Support\Payments\ExactReferenceMatcher;
use App\Support\Scheduling\ScheduledWindowGuard;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The nightly safety net (ADR 0040, replaces "every order of 90 days every night" from ADR 0003). Within the 90-day
 * window it queues only the orders whose result can differ from the stored one — never checked, still pending or
 * unknown, changed after their last check, checked under older rules, or checked while the plan allowed/denied money
 * reconciliation or the store data was fresh/stale differently than now — plus the orders checked longest ago, about
 * a thirtieth of the window per night, so that every order is fully re-checked at least once a month. The
 * unmatched-payment scan of every store is queued as before; unchanged results are not written again
 * (ReconciliationResultRecorder).
 */
class NightlyReconciliationSweep
{
    public const JOB = 'reconciliation.nightly_sweep';

    public const LOOKBACK_DAYS = 90;

    public const RECHECK_DAYS = 30;

    public const MIN_RECHECK_PER_STORE = 100;

    public const STORE_STATUSES = ['onboarding', 'active', 'degraded'];

    public function __construct(
        private readonly ScheduledWindowGuard $guard,
        private readonly DirtySubjectMarker $marker,
        private readonly ExactReferenceMatcher $matcher,
        private readonly EntitlementCalculator $entitlements,
        private readonly ConnectorFreshness $freshness,
    ) {}

    /**
     * @return array{stores: int, orders_marked: int, orders_rechecked: int}|null null when this window already ran
     */
    public function run(?Carbon $windowDate = null): ?array
    {
        $windowKey = ($windowDate ?? Carbon::now())->copy()->utc()->toDateString();

        return $this->guard->runOnce(self::JOB, $windowKey, fn (): array => $this->sweep());
    }

    /**
     * @return array{stores: int, orders_marked: int, orders_rechecked: int}
     */
    private function sweep(): array
    {
        $totals = ['stores' => 0, 'orders_marked' => 0, 'orders_rechecked' => 0];

        Store::query()
            ->whereIn('status', self::STORE_STATUSES)
            ->whereIn('tenant_id', Tenant::query()->where('status', 'active')->select('id'))
            ->orderBy('id')
            ->select(['id', 'tenant_id'])
            ->chunk(100, function ($chunk) use (&$totals): void {
                foreach ($chunk as $store) {
                    $totals['stores']++;
                    $this->matcher->matchUnlinkedCaptures($store->tenant_id, $store->id);
                    $this->marker->markStoreUnmatchedPayments($store->tenant_id, $store->id, ReconciliationDirtySubject::REASON_NIGHTLY_SWEEP, Carbon::now());
                    $totals['orders_marked'] += $this->markChangeable($store);
                    $totals['orders_rechecked'] += $this->markOldest($store);
                }
            });

        return $totals;
    }

    private function markChangeable(Store $store): int
    {
        $entitled = $this->entitlements->forTenant($store->tenant_id)->allows(FeatureCatalog::MONEY_RECONCILIATION);
        $stale = $this->freshness->storeDataStale($store->tenant_id, $store->id);
        $marked = 0;

        $this->window($store)
            ->where(fn (Builder $query) => $query
                ->whereNull('states.id')
                ->orWhereIn('states.worst_status', [ReconciliationFinding::STATUS_PENDING, ReconciliationFinding::STATUS_UNKNOWN])
                ->orWhere('states.algorithm_version', '!=', OrderReconciliationService::ALGORITHM_VERSION)
                ->orWhere('states.config_version', '!=', OrderReconciliationService::CONFIG_VERSION)
                ->orWhereColumn('orders.updated_at', '>', 'states.evaluated_at')
                ->orWhere('states.entitled', ! $entitled)
                ->orWhere('states.store_data_stale', ! $stale))
            ->select('orders.id')
            ->chunkById(DirtySubjectMarker::BULK_CHUNK, function ($orders) use ($store, &$marked): void {
                $marked += $this->marker->markOrdersIfAbsent($store->tenant_id, $store->id, $orders->pluck('id')->all(), ReconciliationDirtySubject::REASON_NIGHTLY_SWEEP);
            }, 'orders.id', 'id');

        return $marked;
    }

    private function markOldest(Store $store): int
    {
        $budget = max(self::MIN_RECHECK_PER_STORE, (int) ceil($this->window($store)->count() / self::RECHECK_DAYS));
        $ids = $this->window($store)
            ->where('states.evaluated_at', '<', Carbon::now()->subDays(self::RECHECK_DAYS))
            ->orderBy('states.evaluated_at')
            ->limit($budget)
            ->pluck('orders.id')
            ->all();

        return $this->marker->markOrdersIfAbsent($store->tenant_id, $store->id, $ids, ReconciliationDirtySubject::REASON_NIGHTLY_SWEEP);
    }

    private function window(Store $store): Builder
    {
        $since = Carbon::now()->subDays(self::LOOKBACK_DAYS);

        return DB::table('orders')
            ->leftJoin('reconciliation_subject_states as states', fn (JoinClause $join) => $join
                ->on('states.subject_id', '=', 'orders.id')
                ->on('states.tenant_id', '=', 'orders.tenant_id')
                ->where('states.subject_type', '=', ReconciliationResultRecorder::SUBJECT_ORDER))
            ->where('orders.tenant_id', $store->tenant_id)
            ->where('orders.store_id', $store->id)
            ->where(fn (Builder $query) => $query
                ->where('orders.source_updated_at', '>=', $since)
                ->orWhere('orders.source_created_at', '>=', $since));
    }
}
