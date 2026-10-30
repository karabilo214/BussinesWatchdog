<?php

namespace App\Support\Sales;

use App\Models\Integration;
use App\Models\Store;
use App\Support\Integrations\ConnectorFreshness;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SalesHistory
{
    public function __construct(private readonly TenantContext $tenants, private readonly ConnectorFreshness $freshness, private readonly SalesCoverage $coverage, private readonly SalesWarmup $warmup, private readonly SalesOrderObservations $observations, private readonly SalesBaseline $baseline) {}

    public function report(string $storeId, CarbonImmutable $from, CarbonImmutable $to, string $mode = 'live'): array
    {
        $tenantId = $this->tenants->requireTenantId('sales.history');
        $from = $from->utc();
        $to = $to->utc();

        if (! in_array($mode, ['live', 'test'], true) || $from->greaterThanOrEqualTo($to) || $to->greaterThan(CarbonImmutable::now()) || $from->diffInHours($to) > 28 * 24) {
            throw new InvalidArgumentException('sales_history_range_invalid');
        }

        $store = Store::query()->where('tenant_id', $tenantId)->findOrFail($storeId);
        $bins = [];

        for ($hour = $from->startOfHour(); $hour->lessThan($to); $hour = $hour->addHour()) {
            $local = $hour->setTimezone($store->timezone);
            $bins[$hour->format('Y-m-d H:00:00')] = [
                'from' => $hour->max($from)->toIso8601String(),
                'to' => $hour->addHour()->min($to)->toIso8601String(),
                'local_hour' => $local->format('Y-m-d\TH:00:00P'),
                'local_weekday' => $local->dayOfWeekIso,
                'orders_created_observed' => 0,
                'store_paid_markers_observed' => 0,
                'orders_with_capture_observed' => 0,
                'coverage' => 'unknown',
            ];
        }

        $latestIds = $this->observations->latestIds($store);
        $orders = fn () => $this->observations->query($store, $mode);

        foreach ($orders()->where('source_created_at', '>=', $from)->where('source_created_at', '<', $to)->select('source_created_at')->cursor() as $order) {
            $bins[$order->source_created_at->utc()->format('Y-m-d H:00:00')]['orders_created_observed']++;
        }

        foreach ($orders()->where('paid_marked_at', '>=', $from)->where('paid_marked_at', '<', $to)->select('paid_marked_at')->cursor() as $order) {
            $bins[$order->paid_marked_at->utc()->format('Y-m-d H:00:00')]['store_paid_markers_observed']++;
        }

        $captures = DB::table('orders as o')
            ->join('orders as linked', fn ($join) => $join->on('linked.external_id', '=', 'o.external_id')->on('linked.tenant_id', '=', 'o.tenant_id')->on('linked.store_id', '=', 'o.store_id')->on('linked.mode', '=', 'o.mode')->on('linked.currency', '=', 'o.currency'))
            ->join('payment_allocations as a', fn ($join) => $join->on('a.order_id', '=', 'linked.id')->on('a.tenant_id', '=', 'linked.tenant_id')->on('a.store_id', '=', 'linked.store_id')->on('a.currency', '=', 'linked.currency'))
            ->join('financial_transactions as t', fn ($join) => $join->on('t.id', '=', 'a.capture_transaction_id')->on('t.tenant_id', '=', 'a.tenant_id')->on('t.store_id', '=', 'a.store_id')->on('t.payment_id', '=', 'a.payment_id')->on('t.currency', '=', 'a.currency'))
            ->join('payments as p', fn ($join) => $join->on('p.id', '=', 't.payment_id')->on('p.tenant_id', '=', 't.tenant_id')->on('p.store_id', '=', 't.store_id')->on('p.integration_id', '=', 't.integration_id')->on('p.currency', '=', 't.currency'))
            ->join('integrations as i', fn ($join) => $join->on('i.id', '=', 'p.integration_id')->on('i.tenant_id', '=', 'p.tenant_id')->on('i.store_id', '=', 'p.store_id'))
            ->where('o.tenant_id', $tenantId)->where('o.store_id', $store->id)->where('o.mode', $mode)->whereIn('o.id', $latestIds)
            ->where('o.is_synthetic', false)->whereNull('o.deleted_at')->whereNull('a.revoked_at')->where('a.amount_minor', '>', 0)
            ->where('t.kind', 'capture')->where('t.status', 'succeeded')->where('t.amount_minor', '>', 0)
            ->where('t.source_authority', Integration::SOURCE_INDEPENDENT_PROVIDER)
            ->where('p.source_authority', Integration::SOURCE_INDEPENDENT_PROVIDER)->where('p.mode', $mode)->whereNull('p.foreign_store_reason')
            ->where('i.source_authority', Integration::SOURCE_INDEPENDENT_PROVIDER)->where('i.mode', $mode)
            ->groupBy('o.id')->selectRaw('min(t.occurred_at) as first_capture_at')
            ->havingRaw('min(t.occurred_at) >= ? and min(t.occurred_at) < ?', [$from, $to]);

        foreach ($captures->cursor() as $capture) {
            $bins[CarbonImmutable::parse($capture->first_capture_at, 'UTC')->utc()->format('Y-m-d H:00:00')]['orders_with_capture_observed']++;
        }

        $sources = Integration::query()->where('tenant_id', $tenantId)->where('store_id', $store->id)
            ->where('source_authority', Integration::SOURCE_STORE_REPORTED)->whereIn('status', [Integration::STATUS_ACTIVE, Integration::STATUS_DEGRADED])->get();
        $reasons = ['historical_coverage_not_recorded', 'thresholds_not_calibrated'];

        if ($sources->count() !== 1) {
            $reasons[] = $sources->isEmpty() ? 'store_source_missing' : 'store_source_ambiguous';
        } else {
            $assessment = $this->freshness->assess($sources->sole());

            if ($assessment['state'] !== ConnectorFreshness::STATE_FRESH) {
                $reasons[] = 'store_source_'.$assessment['state'];
            }
        }

        if ($store->status !== 'active' || $store->capacity_paused_at !== null || $store->tenant->status !== 'active') {
            $reasons[] = 'monitoring_paused';
        }

        $coverage = $this->coverage->forBuckets($store, $mode, array_values($bins));
        $bins = array_values($bins);

        foreach ($bins as $index => &$bucket) {
            $bucket['metric_coverage'] = $coverage[$index];
        }
        unset($bucket);

        if (count(array_filter($coverage, fn (array $item): bool => $item['orders_created'] === 'complete')) === count($coverage)) {
            $reasons = array_values(array_diff($reasons, ['historical_coverage_not_recorded']));
            $reasons[] = 'payment_history_coverage_unknown';
            $reasons[] = 'baseline_not_calibrated';
        }

        return [
            'store_id' => $store->id,
            'mode' => $mode,
            'timezone' => $store->timezone,
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'rule_version' => 'sales-history-v6',
            'status' => 'diagnostic_only',
            'baseline_state' => 'unknown',
            'warmup_stage' => 'unverified_history',
            'order_history_warmup' => $this->warmup->report($store, $mode, $to),
            'order_baseline' => $this->baseline->report($store, $mode, $to),
            'alerts_enabled' => false,
            'reason_codes' => $reasons,
            'buckets' => array_values($bins),
        ];
    }
}
