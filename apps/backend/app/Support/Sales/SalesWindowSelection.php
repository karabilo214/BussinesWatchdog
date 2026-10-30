<?php

namespace App\Support\Sales;

use InvalidArgumentException;

class SalesWindowSelection
{
    public const DEFAULT_MIN_EXPECTED_ORDERS = '20';

    public function minimum(string $value): string
    {
        if (! preg_match('/^[1-9][0-9]*$/D', $value)) {
            throw new InvalidArgumentException('sales_window_min_expected_orders_invalid');
        }

        return $value;
    }

    public function report(array $bands, string $minimum = self::DEFAULT_MIN_EXPECTED_ORDERS, string $kind = 'fixed_local_bands'): array
    {
        if (! in_array($kind, ['fixed_local_bands', 'trailing_elapsed_windows'], true)) {
            throw new InvalidArgumentException('sales_window_kind_invalid');
        }
        $minimum = $this->minimum($minimum);
        $selections = [];
        foreach ($bands as $anchor) {
            if ($anchor['width_hours'] !== 1) {
                continue;
            }
            $attempts = [];
            $selected = null;
            foreach (SalesLocalBands::WIDTHS as $width) {
                $windows = array_values(array_filter($bands, fn (array $band): bool => $band['width_hours'] === $width
                    && $band['target_from'] <= $anchor['target_from'] && $band['target_to'] >= $anchor['target_to']));
                if (count($windows) !== 1) {
                    $attempts[] = ['width_hours' => $width, 'window' => null, 'volume_state' => 'unknown', 'candidates' => [], 'reason_codes' => ['containing_window_missing']];

                    continue;
                }
                $window = $windows[0];
                $profile = $window['warmup_profile'] ?? ['structural_state' => 'insufficient', 'candidates' => [], 'reason_codes' => ['comparison_profile_missing']];
                $candidates = [];
                $reasons = $profile['reason_codes'];
                $state = 'unknown';
                if ($profile['structural_state'] === 'available' && $profile['candidates'] !== [] && $reasons === []) {
                    foreach ($profile['candidates'] as $candidate) {
                        $fraction = $candidate['expected_orders'];
                        $valid = is_array($fraction)
                            && (is_string($fraction['numerator'] ?? null) || is_int($fraction['numerator'] ?? null))
                            && (is_string($fraction['denominator'] ?? null) || is_int($fraction['denominator'] ?? null))
                            && preg_match('/^(0|[1-9][0-9]*)$/D', (string) $fraction['numerator'])
                            && preg_match('/^[1-9][0-9]*$/D', (string) $fraction['denominator']);
                        $meets = $valid ? bccomp((string) $fraction['numerator'], bcmul($minimum, (string) $fraction['denominator'], 0), 0) >= 0 : null;
                        $candidates[] = [...$candidate, 'meets_minimum' => $meets];
                    }
                    $assessments = array_column($candidates, 'meets_minimum');
                    $state = in_array(null, $assessments, true) ? 'unknown' : (in_array(false, $assessments, true) ? 'insufficient' : 'sufficient');
                    if ($state === 'insufficient') {
                        $reasons[] = 'expected_order_volume_below_minimum';
                    } elseif ($state === 'unknown') {
                        $reasons[] = 'expected_order_fraction_invalid';
                    }
                } elseif ($reasons === []) {
                    $reasons[] = 'comparison_candidates_missing';
                }
                $descriptor = $this->descriptor($window);
                $attempts[] = ['width_hours' => $width, 'window' => $descriptor, 'volume_state' => $state, 'candidates' => $candidates, 'reason_codes' => $reasons];
                if ($state === 'sufficient') {
                    $selected = $descriptor;

                    break;
                }
            }
            $selections[] = [
                'anchor' => $this->descriptor($anchor),
                'selection_state' => $selected === null ? 'abstain' : 'selected',
                'selected_window' => $selected,
                'reason_codes' => $selected === null ? array_values(array_unique(array_merge(...array_column($attempts, 'reason_codes')))) : [],
                'attempts' => $attempts,
            ];
        }

        return [
            'status' => 'diagnostic_only',
            'policy' => [
                'version' => $kind === 'fixed_local_bands' ? 'sales-window-volume-v1' : 'sales-rolling-volume-v1',
                'min_expected_orders' => $minimum,
                'default_min_expected_orders' => self::DEFAULT_MIN_EXPECTED_ORDERS,
                'window_kind' => $kind,
                'candidate_requirement' => 'all_stage_candidates',
                'calibration_state' => 'uncalibrated',
            ],
            'statistical_state' => 'uncalibrated',
            'alerts_enabled' => false,
            'reason_codes' => ['thresholds_not_calibrated'],
            'selections' => $selections,
        ];
    }

    private function descriptor(array $band): array
    {
        return array_intersect_key($band, array_flip(['local_start_hour', 'width_hours', 'occurrence', 'occurrences', 'duration_seconds', 'target_from', 'target_to']));
    }
}
