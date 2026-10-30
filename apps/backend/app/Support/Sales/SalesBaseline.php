<?php

namespace App\Support\Sales;

use App\Models\Store;
use Carbon\CarbonImmutable;

class SalesBaseline
{
    public function __construct(private readonly SalesWarmup $warmup, private readonly SalesCoverage $coverage, private readonly SalesOrderObservations $orders, private readonly SalesLocalBands $localBands, private readonly SalesWarmupProfile $warmupProfile) {}

    public function report(Store $store, string $mode, CarbonImmutable $asOf): array
    {
        $warmup = $this->warmup->report($store, $mode, $asOf);
        $target = $asOf->setTimezone($store->timezone)->startOfDay();
        $targetMetric = $this->coverage->forBuckets($store, $mode, [[
            'from' => $target->utc()->toIso8601String(),
            'to' => $target->addDay()->utc()->toIso8601String(),
        ]])[0];
        $weekday = $targetMetric['comparison_weekday'];
        $days = array_values(array_filter($warmup['days'], fn (array $day): bool => $targetMetric['calendar_state'] === 'ready' && $day['eligible'] && $day['calendar_state'] === 'ready'));
        $profiles = [];
        foreach ($days as $day) {
            $layout = $this->localBands->forDay(CarbonImmutable::parse($day['from'])->setTimezone($store->timezone));
            foreach ($layout['bands'] as $band) {
                $profiles[$day['date']][$band['width_hours']][$band['local_start_hour']][] = [
                    ...$band,
                    'from_timestamp' => CarbonImmutable::parse($band['from'])->getTimestamp(),
                    'to_timestamp' => CarbonImmutable::parse($band['to'])->getTimestamp(),
                    'orders_created' => 0,
                ];
            }
        }

        if ($days !== []) {
            $from = CarbonImmutable::parse($warmup['days'][0]['from']);
            foreach ($this->orders->query($store, $mode)->where('source_created_at', '>=', $from)->where('source_created_at', '<', $target->utc())->select('source_created_at')->cursor() as $order) {
                $local = $order->source_created_at->setTimezone($store->timezone);
                $date = $local->toDateString();
                if (! isset($profiles[$date])) {
                    continue;
                }
                $timestamp = $local->getTimestamp();
                foreach (SalesLocalBands::WIDTHS as $width) {
                    $hour = intdiv($local->hour, $width) * $width;
                    foreach ($profiles[$date][$width][$hour] ?? [] as $index => $band) {
                        if ($timestamp >= $band['from_timestamp'] && $timestamp < $band['to_timestamp']) {
                            $profiles[$date][$width][$hour][$index]['orders_created']++;
                        }
                    }
                }
            }
        }

        $bands = [];
        $targetLayout = $this->localBands->forDay($target);
        $targetDaily = array_values(array_filter($targetLayout['bands'], fn (array $band): bool => $band['width_hours'] === 24));
        foreach ($targetLayout['bands'] as $targetBand) {
            $width = $targetBand['width_hours'];
            $hour = $targetBand['local_start_hour'];
            $samples = [];
            $omitted = [];
            $pairedSamples = [];
            $pairedOmitted = [];
            foreach ($days as $day) {
                $candidates = $profiles[$day['date']][$width][$hour] ?? [];
                $sample = $candidates[$targetBand['occurrence'] - 1] ?? null;
                $reason = match (true) {
                    $candidates === [] => 'local_band_absent',
                    count($candidates) !== $targetBand['occurrences'] => 'local_band_occurrences_not_comparable',
                    $sample['duration_seconds'] !== $targetBand['duration_seconds'] => 'dst_duration_not_comparable',
                    default => null,
                };
                if ($reason !== null) {
                    $pairedOmitted[] = ['date' => $day['date'], 'reason' => $reason];
                    if ($day['comparison_weekday'] === $weekday) {
                        $omitted[] = ['date' => $day['date'], 'reason' => $reason];
                    }

                    continue;
                }
                $observation = [
                    'date' => $day['date'],
                    'from' => $sample['from'],
                    'to' => $sample['to'],
                    'local_from' => $sample['local_from'],
                    'local_to' => $sample['local_to'],
                    'occurrence' => $sample['occurrence'],
                    'occurrences' => $sample['occurrences'],
                    'duration_seconds' => $sample['duration_seconds'],
                    'orders_created' => $sample['orders_created'],
                ];
                if ($day['comparison_weekday'] === $weekday) {
                    $samples[] = $observation;
                }
                $daily = $profiles[$day['date']][24][0] ?? [];
                if (count($daily) !== 1 || count($targetDaily) !== 1 || $daily[0]['duration_seconds'] !== $targetDaily[0]['duration_seconds']) {
                    $pairedOmitted[] = ['date' => $day['date'], 'reason' => 'daily_profile_duration_not_comparable'];

                    continue;
                }
                $pairedSamples[] = [
                    ...$observation,
                    'same_weekday' => $day['comparison_weekday'] === $weekday,
                    'comparison_weekday' => $day['comparison_weekday'],
                    'day_from' => $daily[0]['from'],
                    'day_to' => $daily[0]['to'],
                    'day_orders_created' => $daily[0]['orders_created'],
                ];
            }
            $values = array_column($samples, 'orders_created');
            $reasons = $targetMetric['calendar_state'] === 'ready' ? [] : ['holiday_calendar_'.$targetMetric['calendar_state']];
            if ($samples === []) {
                $reasons[] = 'comparable_samples_missing';
            }
            $medianTwice = $samples === [] ? null : $this->medianTwice($values);
            $madFourTimes = $samples === [] ? null : $this->medianTwice(array_map(fn (int $value): int => abs(2 * $value - $medianTwice), $values));
            if ($madFourTimes === 0) {
                $reasons[] = 'dispersion_zero_no_significance_inferred';
            }
            $bands[] = [
                'local_start_hour' => $hour,
                'width_hours' => $width,
                'occurrence' => $targetBand['occurrence'],
                'occurrences' => $targetBand['occurrences'],
                'duration_seconds' => $targetBand['duration_seconds'],
                'target_from' => $targetBand['from'],
                'target_to' => $targetBand['to'],
                'target_local_from' => $targetBand['local_from'],
                'target_local_to' => $targetBand['local_to'],
                'sample_count' => count($samples),
                'expected_orders' => $samples === [] ? null : ['numerator' => $medianTwice, 'denominator' => 2],
                'mad_orders' => $samples === [] ? null : ['numerator' => $madFourTimes, 'denominator' => 4],
                'mean_orders' => $samples === [] ? null : ['numerator' => array_sum($values), 'denominator' => count($samples)],
                'sample_min' => $samples === [] ? null : min($values),
                'sample_max' => $samples === [] ? null : max($values),
                'samples' => $samples,
                'omitted_samples' => $omitted,
                'reason_codes' => $reasons,
                'warmup_profile' => $this->warmupProfile->report(
                    $warmup['stage'], $targetMetric['calendar_state'], $samples,
                    $samples === [] ? null : ['numerator' => $medianTwice, 'denominator' => 2],
                    $pairedSamples, $pairedOmitted,
                ),
            ];
        }

        return [
            'metric' => 'orders_created',
            'store_id' => $store->id,
            'mode' => $mode,
            'timezone' => $store->timezone,
            'status' => 'diagnostic_only',
            'method_version' => 'same-weekday-bands-v3',
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
            'missing_bands' => $targetLayout['missing_bands'],
        ];
    }

    private function medianTwice(array $values): int
    {
        sort($values, SORT_NUMERIC);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 0 ? $values[$middle - 1] + $values[$middle] : 2 * $values[$middle];
    }
}
