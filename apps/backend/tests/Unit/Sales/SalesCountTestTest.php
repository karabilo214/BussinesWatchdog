<?php

namespace Tests\Unit\Sales;

use App\Support\Sales\SalesCountTest;
use PHPUnit\Framework\TestCase;

class SalesCountTestTest extends TestCase
{
    public function test_matches_independent_exact_binomial_sum_references(): void
    {
        $fixture = json_decode(file_get_contents(__DIR__.'/../../fixtures/sales-count-test-reference.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($fixture['cases'] as $case) {
            $result = (new SalesCountTest)->report($case['references'], $case['observed'], '0.01');
            $this->assertNotNull($result['p_value']);
            $this->assertSame(
                bcmul($case['p_numerator'], $result['p_value']['denominator'], 0),
                bcmul($case['p_denominator'], $result['p_value']['numerator'], 0),
            );
            $this->assertFalse($result['alerts_enabled']);
            $this->assertSame('uncalibrated', $result['statistical_state']);
        }
    }

    public function test_exact_alpha_boundary_is_not_rounded_or_replaced_with_a_default(): void
    {
        $test = new SalesCountTest;
        $atBoundary = $test->report([1, 1, 1], 0, '0.421875');
        $belowBoundary = $test->report([1, 1, 1], 0, '0.421874999999999999999999999999');
        $this->assertSame(['numerator' => '27', 'denominator' => '64'], $atBoundary['p_value']);
        $this->assertSame('lower_tail_candidate', $atBoundary['result']);
        $this->assertSame('no_lower_tail_candidate', $belowBoundary['result']);
        $this->assertFalse($belowBoundary['p_le_alpha']);
    }

    public function test_zero_mad_positive_history_uses_count_uncertainty_without_epsilon(): void
    {
        $result = (new SalesCountTest)->report([20, 20, 20, 20], 0, '0.01');
        $this->assertSame('lower_tail_candidate', $result['result']);
        $this->assertSame(['numerator' => '0', 'denominator' => '240'], $result['variance_to_mean']);
        $this->assertNotSame('0', $result['p_value']['numerator']);
        $this->assertTrue($result['p_le_alpha']);
        $this->assertFalse($result['policy']['model_assumption_verified']);
    }

    public function test_high_variability_and_absent_history_abstain(): void
    {
        foreach ([[20, 20, 20, 100], [0, 40, 0, 40]] as $references) {
            $result = (new SalesCountTest)->report($references, 0, '0.01');
            $this->assertSame('abstain', $result['result']);
            $this->assertContains('historical_overdispersion_observed', $result['reason_codes']);
            $this->assertNull($result['p_value']);
        }
        foreach ([[], [20], [-1, 20], ['20', 20], [20.0, 20]] as $references) {
            $this->assertSame('abstain', (new SalesCountTest)->report($references, 0, '0.01')['result']);
        }
        $zero = (new SalesCountTest)->report([0, 0, 0, 0], 0, '0.01');
        $this->assertContains('historical_order_volume_zero', $zero['reason_codes']);
    }

    public function test_non_drop_is_never_promoted_by_an_extremely_permissive_alpha(): void
    {
        $result = (new SalesCountTest)->report([20, 20, 20, 20], 20, '0.99');
        $this->assertFalse($result['observed_below_reference_mean']);
        $this->assertSame('no_lower_tail_candidate', $result['result']);
    }

    public function test_tiny_probability_retains_an_exact_positive_fraction_instead_of_underflow(): void
    {
        $result = (new SalesCountTest)->report([2500, 2500, 2500, 2500], 0, '0.01');
        $this->assertSame('lower_tail_candidate', $result['result']);
        $this->assertNotSame('0', $result['p_value']['numerator']);
        $this->assertSame('0.'.str_repeat('0', 30), $result['p_decimal_bounds']['lower']);
        $this->assertSame('0.'.str_repeat('0', 29).'1', $result['p_decimal_bounds']['upper']);
    }

    public function test_computation_budgets_fail_closed_without_overflow(): void
    {
        foreach ([[2501, 2501, 2501, 2501], [PHP_INT_MAX, PHP_INT_MAX]] as $references) {
            $result = (new SalesCountTest)->report($references, 0, '0.01');
            $this->assertContains('exact_test_computation_budget_exceeded', $result['reason_codes']);
            $this->assertNull($result['p_value']);
        }
        $result = (new SalesCountTest)->report([2500, 2500], 2049, '0.01');
        $this->assertContains('exact_test_computation_budget_exceeded', $result['reason_codes']);
    }

    public function test_alpha_must_be_an_explicit_positive_decimal_below_one(): void
    {
        foreach (['', '0', '1', '1.0', '0.0', '-0.01', '.01', '1e-3', '0.01 ', '0.'.str_repeat('0', 100).'1'] as $value) {
            try {
                (new SalesCountTest)->alpha($value);
                $this->fail('Invalid alpha must fail closed');
            } catch (\InvalidArgumentException $error) {
                $this->assertSame('sales_count_alpha_invalid', $error->getMessage());
            }
        }
        $this->assertSame(['value' => '0.0100', 'numerator' => '100', 'denominator' => '10000'], (new SalesCountTest)->alpha('0.0100'));
    }
}
