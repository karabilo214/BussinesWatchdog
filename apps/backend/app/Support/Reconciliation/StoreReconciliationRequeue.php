<?php

namespace App\Support\Reconciliation;

use App\Models\Order;
use Illuminate\Support\Carbon;

class StoreReconciliationRequeue
{
    public function __construct(
        private readonly DirtySubjectMarker $marker,
    ) {}

    public function requeue(string $tenantId, string $storeId, string $reason): int
    {
        $since = Carbon::now()->subDays(NightlyReconciliationSweep::LOOKBACK_DAYS);
        $marked = 0;

        Order::query()
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where(fn ($query) => $query
                ->where('source_updated_at', '>=', $since)
                ->orWhere('source_created_at', '>=', $since))
            ->orderBy('id')
            ->select('id')
            ->chunk(DirtySubjectMarker::BULK_CHUNK, function ($orders) use ($tenantId, $storeId, $reason, &$marked): void {
                $marked += $this->marker->markOrdersIfAbsent($tenantId, $storeId, $orders->pluck('id')->all(), $reason);
            });

        $this->marker->markStoreUnmatchedPayments($tenantId, $storeId, $reason, Carbon::now());

        return $marked;
    }
}
