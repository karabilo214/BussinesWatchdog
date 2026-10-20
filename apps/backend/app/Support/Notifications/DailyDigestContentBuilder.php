<?php

namespace App\Support\Notifications;

use App\Models\CheckRun;
use App\Models\Incident;
use App\Models\NotificationDelivery;
use App\Models\ReconciliationFinding;
use App\Models\Store;
use App\Models\Tenant;
use App\Support\Integrations\ConnectorFreshness;
use App\Support\Stores\StoreCoverage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The daily summary of one channel (ADR 0067): what is active now, what changed in the last 24 hours and what the
 * service cannot see. "No problems" is only stated when every store in scope is fully covered, as on the overview.
 */
class DailyDigestContentBuilder
{
    public const TEMPLATE_VERSION = 'digest.v1';

    public const PERIOD_HOURS = 24;

    public const INCIDENT_LIMIT = 10;

    public const VERDICT_PROBLEMS = 'problems';

    public const VERDICT_INCOMPLETE = 'incomplete';

    public const VERDICT_ALL_CLEAR = 'all_clear';

    private const SEVERITY_ORDER = [Incident::SEVERITY_CRITICAL, Incident::SEVERITY_WARNING, Incident::SEVERITY_INFO];

    public function __construct(
        private readonly StoreCoverage $coverage,
    ) {}

    /**
     * @param  Collection<int, Store>  $stores
     * @return array<string, mixed>
     */
    public function forChannel(Tenant $tenant, Collection $stores, NotificationPreferences $preferences, Carbon $now): array
    {
        $from = $now->copy()->subHours(self::PERIOD_HOURS);
        $storeIds = $stores->pluck('id')->all();
        $coverage = $this->coverage->forStores($stores);

        $active = Incident::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('store_id', $storeIds)
            ->whereIn('state', Incident::ACTIVE_STATES)
            ->get();
        $opened = Incident::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('store_id', $storeIds)
            ->where('first_seen_at', '>=', $from)
            ->selectRaw('store_id, count(*) as aggregate')
            ->groupBy('store_id')
            ->pluck('aggregate', 'store_id');
        $resolved = Incident::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('store_id', $storeIds)
            ->where('state', Incident::STATE_RESOLVED)
            ->where('resolved_at', '>=', $from)
            ->selectRaw('store_id, count(*) as aggregate')
            ->groupBy('store_id')
            ->pluck('aggregate', 'store_id');
        $checks = CheckRun::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('store_id', $storeIds)
            ->whereNotNull('finished_at')
            ->where('finished_at', '>=', $from)
            ->selectRaw('store_id, status, count(*) as aggregate')
            ->groupBy('store_id', 'status')
            ->get()
            ->groupBy('store_id');

        $storeRows = $stores->map(function (Store $store) use ($coverage, $active, $opened, $resolved, $checks): array {
            $storeCoverage = $coverage[$store->id]['coverage'];
            $storeActive = $active->where('store_id', $store->id);
            $storeChecks = $checks->get($store->id, collect());
            $passed = (int) $storeChecks->where('status', CheckRun::STATUS_PASSED)->sum('aggregate');
            $failed = (int) $storeChecks->where('status', CheckRun::STATUS_FAILED)->sum('aggregate');

            return [
                'name' => $store->name,
                'paused' => $store->capacity_paused_at !== null,
                'active' => collect(self::SEVERITY_ORDER)
                    ->mapWithKeys(fn (string $severity): array => [$severity => $storeActive->where('severity', $severity)->count()])
                    ->all(),
                'opened' => (int) $opened->get($store->id, 0),
                'resolved' => (int) $resolved->get($store->id, 0),
                'checks' => [
                    'passed' => $passed,
                    'failed' => $failed,
                    'other' => (int) $storeChecks->sum('aggregate') - $passed - $failed,
                ],
                'last_successful_check_at' => $coverage[$store->id]['last_successful_check_at'],
                'gaps' => $store->capacity_paused_at !== null ? ['paused'] : $this->gaps($storeCoverage),
            ];
        })->values()->all();

        $storeNames = $stores->pluck('name', 'id');
        $listed = $active
            ->sortBy(fn (Incident $incident): string => array_search($incident->severity, self::SEVERITY_ORDER, true).'|'.$incident->first_seen_at?->toJSON())
            ->take(self::INCIDENT_LIMIT)
            ->map(fn (Incident $incident): array => [
                'store_name' => (string) $storeNames->get($incident->store_id),
                'severity' => $incident->severity,
                'family' => $incident->family,
                'component' => $incident->component,
                'first_seen_at' => $incident->first_seen_at?->toJSON(),
                'link' => $this->link('/app/incidents/'.$incident->id),
            ])
            ->values()
            ->all();

        return [
            'kind' => NotificationDelivery::KIND_DAILY_DIGEST,
            'locale' => $preferences->locale,
            'timezone' => $preferences->timezone,
            'period_from' => $from->toJSON(),
            'period_to' => $now->toJSON(),
            'verdict' => match (true) {
                $active->isNotEmpty() => self::VERDICT_PROBLEMS,
                collect($storeRows)->contains(fn (array $row): bool => $row['gaps'] !== []) => self::VERDICT_INCOMPLETE,
                default => self::VERDICT_ALL_CLEAR,
            },
            'stores' => $storeRows,
            'incidents' => $listed,
            'more_incidents' => max(0, $active->count() - count($listed)),
            ...$this->discrepancies($tenant->id, $storeIds),
            'link' => $this->link('/app'),
        ];
    }

    /**
     * Same parts as the overview's "fully covered" test: a fresh connector, running reconciliation, passing checks.
     *
     * @param  array<string, mixed>  $coverage
     * @return list<string>
     */
    private function gaps(array $coverage): array
    {
        $connector = $coverage['connector']['state'];
        $money = $coverage['money']['state'];
        $browser = $coverage['browser_checks']['state'];

        return array_values(array_filter([
            $connector === ConnectorFreshness::STATE_FRESH ? null : 'connector.'.$connector,
            in_array($money, ['reconciling', 'store_data_stale', 'store_not_connected'], true) ? null : 'money.'.$money,
            $browser === 'passing' ? null : 'browser_checks.'.$browser,
        ]));
    }

    /**
     * Verified amounts of active money incidents, summed by the database per mode, currency and component (ADR 0018);
     * never added across them, and incidents without a verified amount are counted, not treated as zero.
     *
     * @param  list<string>  $storeIds
     * @return array{discrepancies: list<array<string, mixed>>, unknown_amount_incidents: int}
     */
    private function discrepancies(string $tenantId, array $storeIds): array
    {
        $money = Incident::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('store_id', $storeIds)
            ->where('family', 'money')
            ->whereIn('state', Incident::ACTIVE_STATES);

        $rows = (clone $money)
            ->whereNotNull('verified_discrepancy_minor')
            ->whereNotNull('currency')
            ->selectRaw('mode, currency, component, SUM(verified_discrepancy_minor) AS total_minor, COUNT(*) AS incident_count')
            ->groupBy('mode', 'currency', 'component')
            ->orderBy('mode')
            ->orderBy('currency')
            ->orderBy('component')
            ->get();

        $exponents = ReconciliationFinding::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('currency', $rows->pluck('currency')->unique()->values())
            ->whereNotNull('currency_exponent')
            ->distinct()
            ->pluck('currency_exponent', 'currency');

        return [
            'discrepancies' => $rows->map(fn ($row): array => [
                'mode' => $row->mode,
                'currency' => $row->currency,
                'currency_exponent' => isset($exponents[$row->currency]) ? (int) $exponents[$row->currency] : null,
                'component' => $row->component,
                'total_minor' => $this->minorString($row->total_minor),
                'incident_count' => (int) $row->incident_count,
            ])->values()->all(),
            'unknown_amount_incidents' => (clone $money)
                ->where(fn ($query) => $query->whereNull('verified_discrepancy_minor')->orWhereNull('currency'))
                ->count(),
        ];
    }

    /** SUM over bigint comes back as an integer (SQLite) or a numeric string (PostgreSQL); keep it a decimal string. */
    private function minorString(mixed $value): string
    {
        $string = is_string($value) ? $value : (string) (int) $value;

        return preg_replace('/\.0+$/', '', $string) ?? $string;
    }

    private function link(string $path): string
    {
        return rtrim((string) config('app.url'), '/').$path;
    }
}
