<?php

namespace App\Support\Reconciliation;

use App\Models\Order;
use App\Models\ReconciliationDirtySubject;
use App\Models\Store;
use App\Models\Tenant;
use App\Support\Scheduling\ScheduledWindowGuard;
use Illuminate\Support\Carbon;

class NightlyReconciliationSweep
{
    public const JOB = 'reconciliation.nightly_sweep';

    public const LOOKBACK_DAYS = 90;

    public const STORE_STATUSES = ['onboarding', 'active', 'degraded'];

    public function __construct(
        private readonly ScheduledWindowGuard $guard,
        private readonly DirtySubjectMarker $marker,
    ) {}

    /**
     * @return array{stores: int, orders_marked: int}|null null when this window already ran
     */
    public function run(?Carbon $windowDate = null): ?array
    {
        $windowKey = ($windowDate ?? Carbon::now())->copy()->utc()->toDateString();

        return $this->guard->runOnce(self::JOB, $windowKey, fn (): array => $this->markRecentOrders());
    }

    /**
     * @return array{stores: int, orders_marked: int}
     */
    private function markRecentOrders(): array
    {
        $since = Carbon::now()->subDays(self::LOOKBACK_DAYS);
        $stores = 0;
        $ordersMarked = 0;

        Store::query()
            ->whereIn('status', self::STORE_STATUSES)
            ->whereIn('tenant_id', Tenant::query()->where('status', 'active')->select('id'))
            ->orderBy('id')
            ->select(['id', 'tenant_id'])
            ->chunk(100, function ($chunk) use ($since, &$stores, &$ordersMarked): void {
                foreach ($chunk as $store) {
                    $stores++;

                    Order::query()
                        ->where('tenant_id', $store->tenant_id)
                        ->where('store_id', $store->id)
                        ->where(fn ($query) => $query
                            ->where('source_updated_at', '>=', $since)
                            ->orWhere('source_created_at', '>=', $since))
                        ->orderBy('id')
                        ->select('id')
                        ->chunk(DirtySubjectMarker::BULK_CHUNK, function ($orders) use ($store, &$ordersMarked): void {
                            $ordersMarked += $this->marker->markOrdersIfAbsent(
                                $store->tenant_id,
                                $store->id,
                                $orders->pluck('id')->all(),
                                ReconciliationDirtySubject::REASON_NIGHTLY_SWEEP,
                            );
                        });

                    $this->marker->markStoreUnmatchedPayments(
                        $store->tenant_id,
                        $store->id,
                        ReconciliationDirtySubject::REASON_NIGHTLY_SWEEP,
                        Carbon::now(),
                    );
                }
            });

        return ['stores' => $stores, 'orders_marked' => $ordersMarked];
    }
}
