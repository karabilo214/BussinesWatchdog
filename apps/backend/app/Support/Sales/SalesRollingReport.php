<?php

namespace App\Support\Sales;

use App\Models\Store;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

class SalesRollingReport
{
    public function __construct(private readonly TenantContext $tenants, private readonly SalesRollingWindows $geometry, private readonly SalesWarmup $warmup, private readonly SalesCoverage $coverage, private readonly SalesOrderObservations $orders, private readonly SalesWarmupProfile $profiles, private readonly SalesWindowSelection $selection) {}

    public function report(string $storeId, string $mode, CarbonImmutable $asOf, string $lagSeconds, string $minimum = SalesWindowSelection::DEFAULT_MIN_EXPECTED_ORDERS): array
    {
        $tenant = $this->tenants->requireTenantId('sales.rolling');
        $lag = $this->geometry->lag($lagSeconds);
        $this->selection->minimum($minimum);
        if (! in_array($mode, ['live', 'test'], true) || $asOf->greaterThan(CarbonImmutable::now()) || $asOf->microsecond !== 0) {
            throw new InvalidArgumentException('sales_window_range_invalid');
        }
        $store = Store::query()->where('tenant_id', $tenant)->findOrFail($storeId);
        $end = $asOf->utc()->subSeconds($lag);
        $historyEnd = $end->setTimezone($store->timezone)->startOfDay()->utc();
        $trainingCutoff = $end->subHours(24)->toIso8601String();
        $warmup = $this->warmup->report($store, $mode, $historyEnd);
        $historyDays = array_column($warmup['days'], null, 'date');
        $historyFrom = $warmup['days'][0]['from'];
        $contextFrom = $historyEnd->setTimezone($store->timezone)->subDays(SalesCoverage::RETENTION_LOCAL_DAYS);
        $contextDays = [];
        for ($day = $contextFrom; $day->lessThan(CarbonImmutable::parse($historyFrom)); $day = $day->addDay()->startOfDay()) {
            $contextDays[] = ['date' => $day->toDateString(), 'from' => $day->utc()->toIso8601String(), 'to' => $day->addDay()->startOfDay()->utc()->toIso8601String()];
        }
        $contextMetrics = $this->coverage->forBuckets($store, $mode, $contextDays, observedOnly: true);
        foreach ($contextDays as $index => &$day) {
            $day = [...$day, ...$contextMetrics[$index], 'eligible' => $contextMetrics[$index]['baseline_eligible']];
            $historyDays[$day['date']] = $day;
        }
        unset($day);
        $targets = [];
        $targetDays = [];
        foreach (SalesLocalBands::WIDTHS as $width) {
            $targets[$width] = $this->geometry->target($end, $width, $store->timezone);
            foreach ($this->dates($targets[$width], $store->timezone) as $date) {
                $day = CarbonImmutable::parse($date, $store->timezone)->startOfDay();
                $targetDays[$date] = ['date' => $date, 'from' => $day->utc()->toIso8601String(), 'to' => $day->addDay()->startOfDay()->utc()->toIso8601String()];
            }
        }
        $dayMetrics = $this->coverage->forBuckets($store, $mode, array_values($targetDays), observedOnly: true);
        foreach (array_keys($targetDays) as $index => $date) {
            $targetDays[$date] = [...$targetDays[$date], ...$dayMetrics[$index]];
        }
        $targetMetrics = $this->coverage->forBuckets($store, $mode, array_values($targets), observedOnly: true);
        $references = [];
        $omitted = [];
        $intervals = [];
        foreach ($targets as $width => $target) {
            $intervals['target:'.$width] = $target;
            $references[$width] = [];
            $omitted[$width] = [];
            foreach ($warmup['days'] as $day) {
                $mapped = $this->geometry->historical($target, $day['date'], $store->timezone);
                $window = $mapped['window'];
                $reason = $mapped['reason'];
                if ($window !== null) {
                    if ($window['from'] < $contextFrom->utc()->toIso8601String() || $window['to'] > $historyEnd->toIso8601String()) {
                        $reason = 'outside_closed_history';
                    } elseif ($window['to'] > $trainingCutoff) {
                        $reason = 'overlaps_evaluation_window';
                    } else {
                        foreach ($this->dates($window, $store->timezone) as $date) {
                            if (! ($historyDays[$date]['eligible'] ?? false) || ($historyDays[$date]['calendar_state'] ?? null) !== 'ready') {
                                $reason = 'historical_day_not_eligible';
                                break;
                            }
                        }
                    }
                }
                if ($reason !== null) {
                    $omitted[$width][] = ['date' => $day['date'], 'reason' => $reason];

                    continue;
                }
                $references[$width][$day['date']] = $window;
                $intervals[$width.':'.$day['date']] = $window;
            }
        }
        $counts = $this->counts($store, $mode, $intervals);
        $bands = [];
        foreach ($targets as $width => $target) {
            $targetPattern = $this->pattern($target, $targetDays, $store->timezone);
            $samples = [];
            $paired = [];
            $pairedOmitted = $omitted[$width];
            foreach ($references[$width] as $date => $reference) {
                $same = $this->pattern($reference, $historyDays, $store->timezone) === $targetPattern && ! in_array(null, $targetPattern, true);
                $sample = [...$reference, 'date' => $date, 'orders_created' => $counts[$width.':'.$date]];
                if ($same) {
                    $samples[] = $sample;
                }
                $parent = $references[24][$date] ?? null;
                if ($parent === null) {
                    $pairedOmitted[] = ['date' => $date, 'reason' => 'rolling_daily_parent_unavailable'];

                    continue;
                }
                $paired[] = [
                    ...$sample, 'same_weekday' => $same && $this->pattern($parent, $historyDays, $store->timezone) === $this->pattern($targets[24], $targetDays, $store->timezone),
                    'day_from' => $parent['from'], 'day_to' => $parent['to'], 'day_orders_created' => $counts['24:'.$date],
                ];
            }
            $values = array_column($samples, 'orders_created');
            $median = $values === [] ? null : $this->medianTwice($values);
            $expected = $median === null ? null : ['numerator' => $median, 'denominator' => 2];
            $calendarStates = array_map(fn (string $date): string => $targetDays[$date]['calendar_state'], $this->dates($target, $store->timezone));
            $calendar = in_array('not_configured', $calendarStates, true) ? 'not_configured' : (in_array('out_of_range', $calendarStates, true) ? 'out_of_range' : 'ready');
            $profile = $this->profiles->report($warmup['stage'], $calendar, $samples, $expected, $paired, $pairedOmitted);
            $metric = $targetMetrics[array_search($width, SalesLocalBands::WIDTHS, true)];
            $reasons = array_values(array_diff($metric['baseline_exclusions'], ['local_day_boundary', 'holiday', 'incident']));
            if ($metric['orders_created'] !== 'complete') {
                $reasons[] = 'target_orders_coverage_'.$metric['orders_created'];
            }
            $activeProfile = $profile;
            if ($reasons !== []) {
                $activeProfile['structural_state'] = 'insufficient';
                $activeProfile['candidates'] = [];
                $activeProfile['reason_codes'] = array_values(array_unique([...$profile['reason_codes'], ...$reasons]));
            }
            $bands[] = [
                'width_hours' => $width, 'local_start_hour' => CarbonImmutable::parse($target['local_from'])->hour,
                'occurrence' => $target['start_occurrence'], 'occurrences' => $target['start_occurrences'],
                'duration_seconds' => $target['duration_seconds'], 'target_from' => $target['from'], 'target_to' => $target['to'],
                'target_local_from' => $target['local_from'], 'target_local_to' => $target['local_to'],
                'orders_created_observed' => $counts['target:'.$width],
                'orders_created' => $metric['orders_created'] === 'complete' ? $counts['target:'.$width] : null,
                'metric_coverage' => $metric, 'day_pattern' => $targetPattern,
                'sample_count' => count($samples), 'samples' => $samples, 'omitted_samples' => $omitted[$width],
                'expected_orders' => $expected,
                'mad_orders' => $median === null ? null : ['numerator' => $this->medianTwice(array_map(fn (int $value): int => abs(2 * $value - $median), $values)), 'denominator' => 4],
                'historical_profile' => $profile, 'warmup_profile' => $activeProfile,
            ];
        }

        return [
            'rule_version' => 'sales-rolling-v1', 'status' => 'diagnostic_only', 'store_id' => $store->id, 'mode' => $mode, 'timezone' => $store->timezone,
            'as_of' => $asOf->utc()->toIso8601String(), 'lag_seconds' => (string) $lag, 'evaluation_time' => $end->toIso8601String(),
            'clock_policy' => ['version' => 'elapsed-local-endpoints-v1', 'max_lag_seconds' => (string) SalesRollingWindows::MAX_LAG_SECONDS, 'calibration_state' => 'uncalibrated'],
            'history_from' => $historyFrom, 'history_to' => $historyEnd->toIso8601String(), 'context_from' => $contextFrom->utc()->toIso8601String(),
            'context_days' => $contextDays, 'training_cutoff' => $trainingCutoff, 'order_history_warmup' => $warmup,
            'baseline_state' => 'unknown', 'alerts_enabled' => false, 'reason_codes' => ['thresholds_not_calibrated', 'payment_history_coverage_unknown'],
            'windows' => $bands, 'window_selection' => $this->selection->report($bands, $minimum, 'trailing_elapsed_windows'),
        ];
    }

    private function dates(array $window, string $timezone): array
    {
        $from = CarbonImmutable::parse($window['from'])->setTimezone($timezone)->startOfDay();
        $last = CarbonImmutable::parse($window['to'])->subMicrosecond()->setTimezone($timezone)->startOfDay();
        $dates = [];
        for ($day = $from; $day->lessThanOrEqualTo($last); $day = $day->addDay()->startOfDay()) {
            $dates[] = $day->toDateString();
        }

        return $dates;
    }

    private function pattern(array $window, array $days, string $timezone): array
    {
        return array_map(fn (string $date): ?int => ($days[$date]['calendar_state'] ?? null) === 'ready' ? $days[$date]['comparison_weekday'] : null, $this->dates($window, $timezone));
    }

    private function counts(Store $store, string $mode, array $intervals): array
    {
        $edges = [];
        foreach ($intervals as $window) {
            $edges[CarbonImmutable::parse($window['from'])->getTimestamp()] = 0;
            $edges[CarbonImmutable::parse($window['to'])->getTimestamp()] = 0;
        }
        ksort($edges, SORT_NUMERIC);
        $points = array_keys($edges);
        $position = 0;
        $seen = 0;
        foreach ($this->orders->query($store, $mode)->where('source_created_at', '>=', CarbonImmutable::createFromTimestampUTC($points[0]))->where('source_created_at', '<', CarbonImmutable::createFromTimestampUTC($points[count($points) - 1]))->orderBy('source_created_at')->select('source_created_at')->cursor() as $order) {
            $timestamp = $order->source_created_at->getTimestamp();
            while ($position < count($points) && $points[$position] <= $timestamp) {
                $edges[$points[$position++]] = $seen;
            }
            $seen++;
        }
        while ($position < count($points)) {
            $edges[$points[$position++]] = $seen;
        }
        $counts = [];
        foreach ($intervals as $key => $window) {
            $counts[$key] = $edges[CarbonImmutable::parse($window['to'])->getTimestamp()] - $edges[CarbonImmutable::parse($window['from'])->getTimestamp()];
        }

        return $counts;
    }

    private function medianTwice(array $values): int
    {
        sort($values, SORT_NUMERIC);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 0 ? $values[$middle - 1] + $values[$middle] : 2 * $values[$middle];
    }
}
