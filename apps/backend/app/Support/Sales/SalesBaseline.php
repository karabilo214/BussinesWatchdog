<?php

namespace App\Support\Sales;

use App\Models\Store;
use Carbon\CarbonImmutable;

class SalesBaseline
{
    public function __construct(private readonly SalesWarmup $warmup, private readonly SalesCoverage $coverage, private readonly SalesOrderObservations $orders) {}

    public function report(Store $store, string $mode, CarbonImmutable $asOf): array
    {
        $warmup = $this->warmup->report($store, $mode, $asOf);
        $target = $asOf->setTimezone($store->timezone)->startOfDay();
        $targetMetric = $this->coverage->forBuckets($store, $mode, [[
            'from' => $target->utc()->toIso8601String(),
            'to' => $target->addDay()->utc()->toIso8601String(),
        ]])[0];
        $weekday = $targetMetric['comparison_weekday'];
        $days = array_values(array_filter($warmup['days'], fn (array $day): bool => $day['eligible'] && $day['calendar_state'] === 'ready' && $day['comparison_weekday'] === $weekday));
        $counts = [];
        foreach ($days as $day) {
            $counts[$day['date']] = array_fill(0, 24, 0);
        }

        if ($days !== []) {
            $from = CarbonImmutable::parse($warmup['days'][0]['from']);
            foreach ($this->orders->query($store, $mode)->where('source_created_at', '>=', $from)->where('source_created_at', '<', $target->utc())->select('source_created_at')->cursor() as $order) {
                $local = $order->source_created_at->setTimezone($store->timezone);
                if (isset($counts[$local->toDateString()])) {
                    $counts[$local->toDateString()][$local->hour]++;
                }
            }
        }

        $bands = [];
        $targetHasDst = $target->addDay()->getTimestamp() - $target->getTimestamp() !== 86400;
        foreach ([1, 2, 12, 24] as $width) {
            for ($hour = 0; $hour < 24; $hour += $width) {
                $samples = [];
                $omitted = [];
                $targetFrom = $target->setTime($hour, 0);
                $targetTo = $hour + $width === 24 ? $target->addDay() : $target->setTime($hour + $width, 0);
                $targetSeconds = $targetTo->getTimestamp() - $targetFrom->getTimestamp();
                foreach ($days as $day) {
                    $start = CarbonImmutable::parse($day['from'])->setTimezone($store->timezone);
                    $end = CarbonImmutable::parse($day['to']);
                    $sampleFrom = $start->setTime($hour, 0);
                    $sampleTo = $hour + $width === 24 ? $end : $start->setTime($hour + $width, 0);
                    $seconds = $sampleTo->getTimestamp() - $sampleFrom->getTimestamp();
                    if (($width < 24 && ($end->getTimestamp() - $start->getTimestamp() !== 86400 || $targetHasDst)) || $seconds !== $targetSeconds) {
                        $omitted[] = ['date' => $day['date'], 'reason' => 'dst_duration_not_comparable'];

                        continue;
                    }
                    $samples[] = [
                        'date' => $day['date'],
                        'from' => $sampleFrom->utc()->toIso8601String(),
                        'to' => $sampleTo->utc()->toIso8601String(),
                        'orders_created' => array_sum(array_slice($counts[$day['date']], $hour, $width)),
                    ];
                }
                $values = array_column($samples, 'orders_created');
                $reasons = $targetMetric['calendar_state'] === 'ready' ? [] : ['holiday_calendar_'.$targetMetric['calendar_state']];
                if ($samples === []) {
                    $reasons[] = 'comparable_samples_missing';
                }
                if ($width < 24 && $targetHasDst) {
                    $reasons[] = 'target_dst_bands_unsupported';
                }
                $medianTwice = $samples === [] ? null : $this->medianTwice($values);
                $madFourTimes = $samples === [] ? null : $this->medianTwice(array_map(fn (int $value): int => abs(2 * $value - $medianTwice), $values));
                if ($madFourTimes === 0) {
                    $reasons[] = 'dispersion_zero_no_significance_inferred';
                }
                $bands[] = [
                    'local_start_hour' => $hour,
                    'width_hours' => $width,
                    'duration_seconds' => $width < 24 && $targetHasDst ? null : $targetSeconds,
                    'target_from' => $width < 24 && $targetHasDst ? null : $targetFrom->utc()->toIso8601String(),
                    'target_to' => $width < 24 && $targetHasDst ? null : $targetTo->utc()->toIso8601String(),
                    'sample_count' => count($samples),
                    'expected_orders' => $samples === [] ? null : ['numerator' => $medianTwice, 'denominator' => 2],
                    'mad_orders' => $samples === [] ? null : ['numerator' => $madFourTimes, 'denominator' => 4],
                    'mean_orders' => $samples === [] ? null : ['numerator' => array_sum($values), 'denominator' => count($samples)],
                    'sample_min' => $samples === [] ? null : min($values),
                    'sample_max' => $samples === [] ? null : max($values),
                    'samples' => $samples,
                    'omitted_samples' => $omitted,
                    'reason_codes' => $reasons,
                ];
            }
        }

        return [
            'metric' => 'orders_created',
            'store_id' => $store->id,
            'mode' => $mode,
            'timezone' => $store->timezone,
            'status' => 'diagnostic_only',
            'method_version' => 'same-weekday-bands-v1',
            'as_of' => $asOf->utc()->toIso8601String(),
            'target_date' => $target->toDateString(),
            'history_from' => $warmup['days'][0]['from'],
            'history_to' => $target->utc()->toIso8601String(),
            'comparison_weekday' => $weekday,
            'target_exclusions' => $targetMetric['baseline_exclusions'],
            'calendar' => $warmup['calendar'],
            'calendar_state' => $targetMetric['calendar_state'],
            'warmup_stage' => $warmup['stage'],
            'baseline_state' => 'unknown',
            'alerts_enabled' => false,
            'reason_codes' => ['sample_sufficiency_not_calibrated', 'thresholds_not_calibrated', 'payment_history_coverage_unknown'],
            'bands' => $bands,
        ];
    }

    private function medianTwice(array $values): int
    {
        sort($values, SORT_NUMERIC);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 0 ? $values[$middle - 1] + $values[$middle] : 2 * $values[$middle];
    }
}
