<?php

namespace App\Support\Reconciliation;

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
        private readonly StoreReconciliationRequeue $requeue,
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
        $stores = 0;
        $ordersMarked = 0;

        Store::query()
            ->whereIn('status', self::STORE_STATUSES)
            ->whereIn('tenant_id', Tenant::query()->where('status', 'active')->select('id'))
            ->orderBy('id')
            ->select(['id', 'tenant_id'])
            ->chunk(100, function ($chunk) use (&$stores, &$ordersMarked): void {
                foreach ($chunk as $store) {
                    $stores++;

                    $ordersMarked += $this->requeue->requeue($store->tenant_id, $store->id, ReconciliationDirtySubject::REASON_NIGHTLY_SWEEP);
                }
            });

        return ['stores' => $stores, 'orders_marked' => $ordersMarked];
    }
}
