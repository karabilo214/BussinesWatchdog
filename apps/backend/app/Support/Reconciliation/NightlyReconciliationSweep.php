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
 * reconciliation or the store data was fresh/stale differently than now — plus the orders whose fixed night of the
 * 30-night cycle it is (`recheck_slot`), so every order is fully re-checked once per cycle and each night takes about
 * a thirtieth however many orders were checked on one day; an order not checked for longer than the cycle (a night
 * that did not run) is taken as well. The
 * unmatched-payment scan of every store is queued as before; unchanged results are not written again
 * (ReconciliationResultRecorder).
 */
class NightlyReconciliationSweep
{
    public const JOB = 'reconciliation.nightly_sweep';

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
        $night = ($windowDate ?? Carbon::now())->copy()->utc()->startOfDay();

        return $this->guard->runOnce(self::JOB, $night->toDateString(), fn (): array => $this->sweep(ReconciliationResultRecorder::slotOf($night)));
    }

    /**
     * @return array{stores: int, orders_marked: int, orders_rechecked: int}
     */
    private function sweep(int $slot): array
    {
        $totals = ['stores' => 0, 'orders_marked' => 0, 'orders_rechecked' => 0];

        Store::query()
            ->whereIn('status', self::STORE_STATUSES)
            ->whereIn('tenant_id', Tenant::query()->where('status', 'active')->select('id'))
            ->orderBy('id')
            ->select(['id', 'tenant_id'])
            ->chunk(100, function ($chunk) use (&$totals, $slot): void {
                foreach ($chunk as $store) {
                    $totals['stores']++;
                    $this->matcher->matchUnlinkedCaptures($store->tenant_id, $store->id);
                    $this->marker->markStoreUnmatchedPayments($store->tenant_id, $store->id, ReconciliationDirtySubject::REASON_NIGHTLY_SWEEP, Carbon::now());
                    $totals['orders_marked'] += $this->markChangeable($store);
                    $totals['orders_rechecked'] += $this->markCycle($store, $slot);
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

    private function markCycle(Store $store, int $slot): int
    {
        $marked = 0;

        $this->window($store)
            ->whereNotNull('states.id')
            ->where(fn (Builder $query) => $query
                ->where('states.recheck_slot', $slot)
                ->orWhere('states.evaluated_at', '<', Carbon::now()->subDays(ReconciliationResultRecorder::RECHECK_DAYS)))
            ->select('orders.id')
            ->chunkById(DirtySubjectMarker::BULK_CHUNK, function ($orders) use ($store, &$marked): void {
                $marked += $this->marker->markOrdersIfAbsent($store->tenant_id, $store->id, $orders->pluck('id')->all(), ReconciliationDirtySubject::REASON_NIGHTLY_SWEEP);
            }, 'orders.id', 'id');

        return $marked;
    }

    public static function lookbackDays(): int
    {
        return (int) config('watchdog.reconciliation.lookback_days');
    }

    private function window(Store $store): Builder
    {
        $since = Carbon::now()->subDays(self::lookbackDays());

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
