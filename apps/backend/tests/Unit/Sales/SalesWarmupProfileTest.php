<?php

namespace Tests\Unit\Sales;

use App\Support\Sales\SalesWarmupProfile;
use PHPUnit\Framework\TestCase;

class SalesWarmupProfileTest extends TestCase
{
    public function test_profile_fraction_does_not_overflow_into_a_float(): void
    {
        $samples = [];
        for ($day = 0; $day < 7; $day++) {
            $samples[] = ['same_weekday' => $day === 0, 'day_orders_created' => 10000000000, 'orders_created' => 5000000000];
        }
        $report = (new SalesWarmupProfile)->report('stoppage_only', 'ready', [['orders_created' => 5000000000]], ['numerator' => 10000000000, 'denominator' => 2], $samples, []);
        $this->assertSame('available', $report['structural_state']);
        $this->assertSame(['numerator' => '700000000000000000000', 'denominator' => '140000000000'], $report['candidates'][0]['expected_orders']);
        $this->assertSame('uncalibrated', $report['statistical_state']);
        $this->assertFalse($report['alerts_enabled']);
    }

    public function test_full_stage_can_retain_covered_zero_samples_without_inventing_a_profile_share(): void
    {
        $samples = array_fill(0, 4, ['orders_created' => 0]);
        $report = (new SalesWarmupProfile)->report('full', 'ready', $samples, ['numerator' => 0, 'denominator' => 2], [], []);
        $this->assertSame('available', $report['structural_state']);
        $this->assertNull($report['daily_profile']['pooled_band_share']);
        $this->assertSame([['method' => 'same_weekday_band', 'expected_orders' => ['numerator' => '0', 'denominator' => '2']]], $report['candidates']);
        $this->assertFalse($report['alerts_enabled']);
    }

    public function test_major_drop_requires_two_paired_weekday_days_despite_large_pooled_volume(): void
    {
        $report = (new SalesWarmupProfile)->report('major_drop', 'ready', [['orders_created' => 2], ['orders_created' => 4]], ['numerator' => 6, 'denominator' => 2], [
            ['same_weekday' => true, 'day_orders_created' => 10, 'orders_created' => 2],
            ['same_weekday' => false, 'day_orders_created' => 1000000, 'orders_created' => 100000],
        ], []);
        $this->assertSame('insufficient', $report['structural_state']);
        $this->assertContains('weekday_daily_samples_missing', $report['reason_codes']);
        $this->assertSame([], $report['candidates']);
        $this->assertNotNull($report['daily_profile']['expected_orders']);
    }

    public function test_collecting_and_unknown_calendar_do_not_promote_available_raw_estimates(): void
    {
        $weekday = array_fill(0, 4, ['orders_created' => 2]);
        $paired = array_fill(0, 4, ['same_weekday' => true, 'day_orders_created' => 4, 'orders_created' => 2]);
        foreach ([['collecting', 'ready', 'collecting_history'], ['full', 'out_of_range', 'holiday_calendar_out_of_range']] as [$stage, $calendar, $reason]) {
            $report = (new SalesWarmupProfile)->report($stage, $calendar, $weekday, ['numerator' => 4, 'denominator' => 2], $paired, []);
            $this->assertSame([], $report['candidates']);
            $this->assertContains($reason, $report['reason_codes']);
            $this->assertFalse($report['alerts_enabled']);
        }
    }
}
