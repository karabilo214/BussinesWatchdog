<?php

namespace App\Support\Sales;

use InvalidArgumentException;

class SalesCountTest
{
    public const MAX_TOTAL_COUNT = '10000';

    public const MAX_OBSERVED_COUNT = 2048;

    public const DECIMAL_PLACES = 30;

    public const MAX_ALPHA_DECIMAL_PLACES = 100;

    public function alpha(string $value): array
    {
        if (! preg_match('/^0\.[0-9]+$/D', $value) || strlen($value) - 2 > self::MAX_ALPHA_DECIMAL_PLACES) {
            throw new InvalidArgumentException('sales_count_alpha_invalid');
        }
        $digits = substr($value, 2);
        $denominator = '1'.str_repeat('0', strlen($digits));
        if (bccomp($digits, '0', 0) === 0) {
            throw new InvalidArgumentException('sales_count_alpha_invalid');
        }

        return ['value' => $value, 'numerator' => bcadd($digits, '0', 0), 'denominator' => $denominator];
    }

    public function report(array $references, int $observed, string $alpha): array
    {
        $level = $this->alpha($alpha);
        $base = [
            'method' => 'conditional-poisson-lower-tail-v1',
            'alpha' => $level,
            'policy' => [
                'max_total_count' => self::MAX_TOTAL_COUNT, 'max_observed_count' => self::MAX_OBSERVED_COUNT,
                'max_reference_windows' => 28, 'probability_decimal_places' => self::DECIMAL_PLACES,
                'max_alpha_decimal_places' => self::MAX_ALPHA_DECIMAL_PLACES,
                'dispersion_screen' => 'sample_variance_lte_mean',
                'model_assumption_verified' => false, 'window_selection_adjusted' => false,
                'multiple_testing_corrected' => false, 'sequential_testing_corrected' => false,
            ],
            'result' => 'abstain', 'p_value' => null, 'p_le_alpha' => null,
            'statistical_state' => 'uncalibrated', 'alerts_enabled' => false,
        ];
        if ($observed < 0 || count($references) < 2 || count($references) > 28) {
            return [...$base, 'reason_codes' => ['invalid_or_insufficient_reference_counts']];
        }
        $sum = '0';
        $squares = '0';
        foreach ($references as $count) {
            if (! is_int($count) || $count < 0) {
                return [...$base, 'reason_codes' => ['invalid_or_insufficient_reference_counts']];
            }
            $sum = bcadd($sum, (string) $count, 0);
            $squares = bcadd($squares, bcmul((string) $count, (string) $count, 0), 0);
        }
        $n = count($references);
        $base['reference_windows'] = $n;
        $base['historical_total'] = $sum;
        $base['observed'] = $observed;
        if ($sum === '0') {
            return [...$base, 'reason_codes' => ['historical_order_volume_zero']];
        }
        $dispersionNumerator = bcsub(bcmul((string) $n, $squares, 0), bcmul($sum, $sum, 0), 0);
        $dispersionDenominator = bcmul((string) ($n - 1), $sum, 0);
        $base['variance_to_mean'] = ['numerator' => $dispersionNumerator, 'denominator' => $dispersionDenominator];
        if (bccomp($dispersionNumerator, $dispersionDenominator, 0) > 0) {
            return [...$base, 'reason_codes' => ['historical_overdispersion_observed']];
        }
        $total = bcadd($sum, (string) $observed, 0);
        if (bccomp($total, self::MAX_TOTAL_COUNT, 0) > 0 || $observed > self::MAX_OBSERVED_COUNT) {
            return [...$base, 'reason_codes' => ['exact_test_computation_budget_exceeded']];
        }
        $denominator = bcpow((string) ($n + 1), $total, 0);
        $term = bcpow((string) $n, $total, 0);
        $numerator = $term;
        for ($j = 1; $j <= $observed; $j++) {
            $term = bcdiv(bcmul($term, bcsub($total, (string) ($j - 1), 0), 0), (string) ($j * $n), 0);
            $numerator = bcadd($numerator, $term, 0);
        }
        $scale = '1'.str_repeat('0', self::DECIMAL_PLACES);
        $scaled = bcmul($numerator, $scale, 0);
        $lower = bcdiv($scaled, $denominator, 0);
        $upper = bcmod($scaled, $denominator, 0) === '0' ? $lower : bcadd($lower, '1', 0);
        $meets = bccomp(bcmul($numerator, $level['denominator'], 0), bcmul($level['numerator'], $denominator, 0), 0) <= 0;
        $lowerObserved = bccomp(bcmul((string) $observed, (string) $n, 0), $sum, 0) < 0;

        return [
            ...$base, 'result' => $meets && $lowerObserved ? 'lower_tail_candidate' : 'no_lower_tail_candidate',
            'p_value' => ['numerator' => $numerator, 'denominator' => $denominator],
            'p_decimal_bounds' => ['lower' => bcdiv($lower, $scale, self::DECIMAL_PLACES), 'upper' => bcdiv($upper, $scale, self::DECIMAL_PLACES)],
            'p_le_alpha' => $meets, 'observed_below_reference_mean' => $lowerObserved, 'reason_codes' => [],
        ];
    }
}
