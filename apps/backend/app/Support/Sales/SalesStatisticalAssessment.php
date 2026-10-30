<?php

namespace App\Support\Sales;

use Carbon\CarbonImmutable;

class SalesStatisticalAssessment
{
    public function __construct(private readonly SalesCountTest $test) {}

    public function report(array $bands, array $selection, ?string $alpha): array
    {
        $level = $alpha === null ? null : $this->test->alpha($alpha);
        $base = ['method' => 'selected-window-count-assessment-v1', 'alpha' => $level, 'result' => 'abstain', 'statistical_state' => 'uncalibrated', 'alerts_enabled' => false, 'test' => null];
        if ($alpha === null) {
            return [...$base, 'result' => 'not_requested', 'reason_codes' => ['explicit_alpha_required']];
        }
        $selected = $selection['selections'][0]['selected_window'] ?? null;
        if ($selected === null) {
            return [...$base, 'reason_codes' => ['no_volume_qualified_completed_window']];
        }
        $matches = array_values(array_filter($bands, fn (array $band): bool => $band['width_hours'] === $selected['width_hours'] && $band['target_from'] === $selected['target_from'] && $band['target_to'] === $selected['target_to']));
        if (count($matches) !== 1) {
            return [...$base, 'reason_codes' => ['selected_window_missing']];
        }
        $window = $matches[0];
        $base['selected_window'] = $selected;
        $duration = CarbonImmutable::parse($window['target_to'])->getTimestamp() - CarbonImmutable::parse($window['target_from'])->getTimestamp();
        if ($duration !== $window['duration_seconds'] || $window['orders_created'] === null || $window['metric_coverage']['orders_created'] !== 'complete' || $window['warmup_profile']['structural_state'] !== 'available' || count($window['samples']) < ($window['warmup_profile']['required_same_weekday_samples'] ?? PHP_INT_MAX)) {
            return [...$base, 'reason_codes' => ['selected_window_not_comparable']];
        }
        if (array_column($window['warmup_profile']['candidates'], 'method') !== ['same_weekday_band']) {
            return [...$base, 'reason_codes' => ['profile_distribution_not_calibrated']];
        }
        $samples = $window['samples'];
        $previousEnd = null;
        usort($samples, fn (array $a, array $b): int => strcmp($a['from'], $b['from']));
        foreach ($samples as $sample) {
            $sampleDuration = CarbonImmutable::parse($sample['to'])->getTimestamp() - CarbonImmutable::parse($sample['from'])->getTimestamp();
            if ($sampleDuration !== $duration || $sample['duration_seconds'] !== $duration || $sample['from'] >= $sample['to'] || $sample['to'] > $window['target_from'] || ($previousEnd !== null && $sample['from'] < $previousEnd)) {
                return [...$base, 'reason_codes' => ['reference_windows_overlap_or_differ_in_exposure']];
            }
            $previousEnd = $sample['to'];
        }
        $result = $this->test->report(array_map(fn (array $sample): mixed => $sample['orders_created'] ?? null, $samples), $window['orders_created'], $alpha);

        return [...$base, 'result' => $result['result'], 'test' => $result, 'reason_codes' => $result['reason_codes']];
    }
}
