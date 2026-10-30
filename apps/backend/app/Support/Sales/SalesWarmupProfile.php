<?php

namespace App\Support\Sales;

class SalesWarmupProfile
{
    public function report(string $stage, string $calendarState, array $weekdaySamples, ?array $weekdayExpected, array $pairedSamples, array $omitted): array
    {
        $methods = match ($stage) {
            'stoppage_only' => ['weekday_daily_profile'],
            'major_drop' => ['same_weekday_band', 'weekday_daily_profile'],
            'full' => ['same_weekday_band'],
            default => [],
        };
        $required = match ($stage) {
            'stoppage_only' => 1,
            'major_drop' => 2,
            'full' => 4,
            default => null,
        };
        $weekdayDays = array_values(array_filter($pairedSamples, fn (array $sample): bool => $sample['same_weekday']));
        $bandTotal = '0';
        $dayTotal = '0';
        foreach ($pairedSamples as $sample) {
            $bandTotal = bcadd($bandTotal, (string) $sample['orders_created'], 0);
            $dayTotal = bcadd($dayTotal, (string) $sample['day_orders_created'], 0);
        }
        $dailyMedian = $weekdayDays === [] ? null : $this->medianTwice(array_column($weekdayDays, 'day_orders_created'));
        $share = $dayTotal === '0' ? null : ['numerator' => $bandTotal, 'denominator' => $dayTotal];
        $profileExpected = $dailyMedian === null || $share === null ? null : [
            'numerator' => bcmul($dailyMedian, $bandTotal, 0),
            'denominator' => bcmul('2', $dayTotal, 0),
        ];
        $reasons = [];
        if ($calendarState !== 'ready') {
            $reasons[] = 'holiday_calendar_'.$calendarState;
        }
        if ($required === null) {
            $reasons[] = 'collecting_history';
        } else {
            if (count($weekdaySamples) < $required) {
                $reasons[] = 'same_weekday_samples_missing';
            }
            if (in_array('weekday_daily_profile', $methods, true)) {
                if (count($weekdayDays) < $required) {
                    $reasons[] = 'weekday_daily_samples_missing';
                }
                if ($share === null) {
                    $reasons[] = 'pooled_profile_order_volume_missing';
                }
            }
        }
        $candidates = [];
        if ($reasons === []) {
            foreach ($methods as $method) {
                $expected = $method === 'weekday_daily_profile' ? $profileExpected : $weekdayExpected;
                $candidates[] = ['method' => $method, 'expected_orders' => [
                    'numerator' => (string) $expected['numerator'],
                    'denominator' => (string) $expected['denominator'],
                ]];
            }
        }

        return [
            'stage' => $stage,
            'method_version' => 'paired-daily-profile-v1',
            'required_same_weekday_samples' => $required,
            'same_weekday_sample_count' => count($weekdaySamples),
            'structural_state' => $reasons === [] ? 'available' : 'insufficient',
            'statistical_state' => 'uncalibrated',
            'alerts_enabled' => false,
            'reason_codes' => $reasons,
            'candidates' => $candidates,
            'daily_profile' => [
                'sample_count' => count($pairedSamples),
                'weekday_day_sample_count' => count($weekdayDays),
                'weekday_daily_median' => $dailyMedian === null ? null : ['numerator' => $dailyMedian, 'denominator' => '2'],
                'pooled_band_share' => $share,
                'expected_orders' => $profileExpected,
                'samples' => $pairedSamples,
                'omitted_samples' => $omitted,
            ],
        ];
    }

    private function medianTwice(array $values): string
    {
        sort($values, SORT_NUMERIC);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 0
            ? bcadd((string) $values[$middle - 1], (string) $values[$middle], 0)
            : bcmul('2', (string) $values[$middle], 0);
    }
}
