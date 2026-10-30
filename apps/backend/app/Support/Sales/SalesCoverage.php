<?php

namespace App\Support\Sales;

use App\Models\EventInbox;
use App\Models\Incident;
use App\Models\Integration;
use App\Models\Store;
use App\Support\Billing\EntitlementCalculator;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SalesCoverage
{
    public function __construct(private readonly TenantContext $tenants) {}

    public function recordRead(string $integrationId, CarbonImmutable $from, CarbonImmutable $to, string $kind, bool $complete, bool $withoutSkippedObjects): void
    {
        $tenantId = $this->tenants->requireTenantId('sales.coverage.record');
        $now = CarbonImmutable::now()->utc();
        $from = $from->microsecond === 0 ? $from->utc() : $from->utc()->startOfSecond()->addSecond();
        $to = $to->utc()->startOfSecond();

        if ($from->greaterThanOrEqualTo($to) || $to->greaterThan($now) || ! in_array($kind, ['recent', 'audit'], true)) {
            throw new InvalidArgumentException('sales_coverage_read_invalid');
        }

        DB::transaction(function () use ($tenantId, $integrationId, $from, $to, $kind, $complete, $withoutSkippedObjects, $now): void {
            $integration = Integration::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($integrationId);

            if ($integration->source_authority !== Integration::SOURCE_STORE_REPORTED || ! in_array($integration->provider, ['woocommerce_api', 'shopware'], true)) {
                throw new InvalidArgumentException('sales_coverage_source_invalid');
            }

            $journal = $integration->sales_coverage ?? ['version' => 1, 'first_observed_at' => $now->toIso8601String(), 'intervals' => [], 'pending' => []];
            $journal['last_read'] = ['from' => $from->utc()->toIso8601String(), 'to' => $to->utc()->toIso8601String(), 'finished_at' => $now->toIso8601String(), 'kind' => $kind, 'complete' => $complete && $withoutSkippedObjects];

            if ($complete && $withoutSkippedObjects) {
                $pending = $journal['pending'][$kind] ?? null;

                if ($pending === null || CarbonImmutable::parse($pending['to'])->lessThanOrEqualTo($to)) {
                    $journal['pending'][$kind] = $journal['last_read'];
                }
            }

            $integration->forceFill(['sales_coverage' => $this->finalized($integration, $journal, $now)])->save();
        });
    }

    public function finalize(string $integrationId): void
    {
        $tenantId = $this->tenants->requireTenantId('sales.coverage.finalize');
        DB::transaction(function () use ($tenantId, $integrationId): void {
            $integration = Integration::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($integrationId);

            if ($integration->sales_coverage === null) {
                return;
            }

            $next = $this->finalized($integration, $integration->sales_coverage, CarbonImmutable::now()->utc());

            if ($next !== $integration->sales_coverage) {
                $integration->forceFill(['sales_coverage' => $next])->save();
            }
        });
    }

    public function excludeDay(string $storeId, string $date, string $kind, string $mode = 'live'): void
    {
        $tenantId = $this->tenants->requireTenantId('sales.coverage.exclude');

        if (! in_array($kind, ['promotion', 'holiday', 'pause', 'data_gap'], true) || ! in_array($mode, ['live', 'test'], true) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new InvalidArgumentException('sales_exclusion_invalid');
        }

        DB::transaction(function () use ($tenantId, $storeId, $date, $kind, $mode): void {
            $store = Store::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($storeId);
            $day = CarbonImmutable::parse($date, $store->timezone)->startOfDay();
            $today = CarbonImmutable::now($store->timezone)->startOfDay();

            if ($day->format('Y-m-d') !== $date || $day->lessThan($today->subDays(28)) || $day->greaterThan($today)) {
                throw new InvalidArgumentException('sales_exclusion_range_invalid');
            }

            $entries = $store->sales_baseline_exclusions ?? [];
            $entries[$mode.':'.$date.':'.$kind] = ['date' => $date, 'kind' => $kind, 'mode' => $mode, 'timezone' => $store->timezone, 'from' => $day->utc()->toIso8601String(), 'to' => $day->addDay()->utc()->toIso8601String()];
            $cutoff = CarbonImmutable::now()->utc()->subDays(28)->startOfDay();
            $entries = array_filter($entries, fn (array $entry): bool => CarbonImmutable::parse($entry['to'])->greaterThan($cutoff));
            ksort($entries);
            if ($entries !== ($store->sales_baseline_exclusions ?? [])) {
                $store->forceFill(['sales_baseline_exclusions' => $entries])->save();
            }
        });
    }

    public function pruneExclusions(string $storeId): void
    {
        $tenantId = $this->tenants->requireTenantId('sales.coverage.prune');
        DB::transaction(function () use ($tenantId, $storeId): void {
            $store = Store::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($storeId);
            $entries = $store->sales_baseline_exclusions ?? [];
            $cutoff = CarbonImmutable::now()->utc()->subDays(28)->startOfDay();
            $next = array_filter($entries, fn (array $entry): bool => CarbonImmutable::parse($entry['to'])->greaterThan($cutoff));

            if ($next !== $entries) {
                $store->forceFill(['sales_baseline_exclusions' => $next])->save();
            }
        });
    }

    public function isPaused(Store $store): bool
    {
        if ($store->tenant_id !== $this->tenants->requireTenantId('sales.coverage.pause')) {
            throw new InvalidArgumentException('sales_coverage_scope_invalid');
        }

        return $store->status !== 'active' || $store->capacity_paused_at !== null || $store->tenant->status !== 'active'
            || (EntitlementCalculator::enforced() && ! app(EntitlementCalculator::class)->forTenant($store->tenant_id)->serviceActive());
    }

    public function forBuckets(Store $store, string $mode, array $buckets): array
    {
        $tenantId = $this->tenants->requireTenantId('sales.coverage.read');

        if ($store->tenant_id !== $tenantId || ! in_array($mode, ['live', 'test'], true)) {
            throw new InvalidArgumentException('sales_coverage_scope_invalid');
        }

        $sources = Integration::query()->where('tenant_id', $tenantId)->where('store_id', $store->id)->get();
        $intervals = $sources->flatMap(fn (Integration $integration): array => ($integration->sales_coverage['version'] ?? null) === 1 ? ($integration->sales_coverage['intervals'] ?? []) : [])->all();
        $paused = $this->isPaused($store);
        $pending = EventInbox::query()->where('tenant_id', $tenantId)->where('store_id', $store->id)
            ->whereIn('event_type', [EventInbox::EVENT_ORDER_SNAPSHOT, EventInbox::EVENT_ORDER_DELETED])->where('status', '!=', EventInbox::STATUS_PROCESSED)->exists();
        $pending = $pending || $this->unprojectedOrders($tenantId, $store->id, $sources->where('source_authority', Integration::SOURCE_STORE_REPORTED)->whereIn('status', [Integration::STATUS_ACTIVE, Integration::STATUS_DEGRADED])->pluck('id')->all());
        $incidents = Incident::query()->where('tenant_id', $tenantId)->where('store_id', $store->id)
            ->where(fn ($query) => $query->whereNull('mode')->orWhere('mode', $mode))->get(['first_bad_at', 'created_at', 'resolved_at']);
        $exclusions = Store::query()->where('tenant_id', $tenantId)->findOrFail($store->id)->sales_baseline_exclusions ?? [];
        $result = [];

        foreach ($buckets as $bucket) {
            $from = CarbonImmutable::parse($bucket['from']);
            $to = CarbonImmutable::parse($bucket['to']);
            $coverage = $pending ? 'awaiting_projection' : ($this->covers($intervals, $from, $to) ? 'complete' : 'unknown');
            $day = $from->setTimezone($store->timezone)->startOfDay();
            $end = $day->addDay();
            $reasons = [];

            foreach ($exclusions as $exclusion) {
                if ($exclusion['mode'] === $mode && CarbonImmutable::parse($exclusion['from'])->lessThan($end) && CarbonImmutable::parse($exclusion['to'])->greaterThan($day)) {
                    $reasons[] = $exclusion['kind'];
                }
            }

            foreach ($incidents as $incident) {
                if (CarbonImmutable::parse($incident->first_bad_at ?? $incident->created_at)->lessThan($end) && ($incident->resolved_at === null || $incident->resolved_at->greaterThan($day))) {
                    $reasons[] = 'incident';
                    break;
                }
            }

            if ($paused) {
                $reasons[] = 'monitoring_paused';
            }

            $result[] = ['orders_created' => $coverage, 'store_paid_markers' => 'unknown', 'orders_with_capture' => 'unknown', 'baseline_eligible' => $coverage === 'complete' && $reasons === [], 'baseline_exclusions' => array_values(array_unique($reasons))];
        }

        return $result;
    }

    private function finalized(Integration $integration, array $journal, CarbonImmutable $now): array
    {
        $intervals = $journal['intervals'] ?? [];
        $cutoff = $now->subDays(28);

        foreach ($journal['pending'] ?? [] as $kind => $read) {
            $blocked = EventInbox::query()->where('tenant_id', $integration->tenant_id)->where('store_id', $integration->store_id)->where('integration_id', $integration->id)
                ->whereIn('event_type', [EventInbox::EVENT_ORDER_SNAPSHOT, EventInbox::EVENT_ORDER_DELETED])->where('received_at', '<', CarbonImmutable::parse($read['finished_at'])->startOfSecond()->addSecond())
                ->where('status', '!=', EventInbox::STATUS_PROCESSED)->exists();

            if (! $blocked && ! $this->unprojectedOrders($integration->tenant_id, $integration->store_id, [$integration->id])) {
                $intervals[] = ['from' => $read['from'], 'to' => $read['to']];
                unset($journal['pending'][$kind]);
            }
        }

        usort($intervals, fn (array $a, array $b): int => CarbonImmutable::parse($a['from'])->getTimestamp() <=> CarbonImmutable::parse($b['from'])->getTimestamp());
        $merged = [];

        foreach ($intervals as $interval) {
            $start = CarbonImmutable::parse($interval['from'])->max($cutoff);
            $end = CarbonImmutable::parse($interval['to']);

            if ($start->greaterThanOrEqualTo($end)) {
                continue;
            }

            $last = count($merged) - 1;

            if ($last >= 0 && CarbonImmutable::parse($merged[$last]['to'])->greaterThanOrEqualTo($start)) {
                $merged[$last]['to'] = CarbonImmutable::parse($merged[$last]['to'])->max($end)->toIso8601String();
            } else {
                $merged[] = ['from' => $start->toIso8601String(), 'to' => $end->toIso8601String()];
            }
        }

        $journal['intervals'] = $merged;

        return $journal;
    }

    private function unprojectedOrders(string $tenantId, string $storeId, array $integrationIds): bool
    {
        return DB::table('provider_object_states as s')->where('s.tenant_id', $tenantId)->where('s.store_id', $storeId)
            ->whereIn('s.integration_id', $integrationIds)->where('s.object_type', 'order')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')->from('orders as o')->whereColumn('o.tenant_id', 's.tenant_id')->whereColumn('o.store_id', 's.store_id')
                    ->whereColumn('o.integration_id', 's.integration_id')->whereColumn('o.external_id', 's.object_id')->whereColumn('o.source_revision', '>=', 's.revision');
            })->exists();
    }

    private function covers(array $intervals, CarbonImmutable $from, CarbonImmutable $to): bool
    {
        usort($intervals, fn (array $a, array $b): int => CarbonImmutable::parse($a['from'])->getTimestamp() <=> CarbonImmutable::parse($b['from'])->getTimestamp());
        $cursor = $from;

        foreach ($intervals as $interval) {
            $start = CarbonImmutable::parse($interval['from']);
            $end = CarbonImmutable::parse($interval['to']);

            if ($end->lessThanOrEqualTo($cursor)) {
                continue;
            }

            if ($start->greaterThan($cursor)) {
                return false;
            }

            $cursor = $cursor->max($end);

            if ($cursor->greaterThanOrEqualTo($to)) {
                return true;
            }
        }

        return false;
    }
}
