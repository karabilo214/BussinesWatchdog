<?php

namespace Tests\Unit\Sales;

use App\Support\Sales\SalesLocalBands;
use App\Support\Sales\SalesWindowSelection;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class SalesWindowSelectionTest extends TestCase
{
    public function test_selects_the_smallest_containing_window_with_enough_expected_orders(): void
    {
        $report = (new SalesWindowSelection)->report([
            $this->band(10, 1, ['9']), $this->band(10, 2, ['20']),
            $this->band(0, 12, ['100']), $this->band(0, 24, ['200']),
        ]);
        $selection = $report['selections'][0];
        $this->assertSame('selected', $selection['selection_state']);
        $this->assertSame(2, $selection['selected_window']['width_hours']);
        $this->assertSame([1, 2], array_column($selection['attempts'], 'width_hours'));
        $this->assertSame(['insufficient', 'sufficient'], array_column($selection['attempts'], 'volume_state'));
        $this->assertSame('uncalibrated', $report['statistical_state']);
        $this->assertFalse($report['alerts_enabled']);
    }

    public function test_fraction_comparison_keeps_the_exact_boundary_beyond_float_precision(): void
    {
        $small = $this->band(10, 1, ['1']);
        $small['warmup_profile']['candidates'][0]['expected_orders'] = ['numerator' => '1999999999999999999999', 'denominator' => '100000000000000000000'];
        $wide = $this->band(10, 2, ['1']);
        $wide['warmup_profile']['candidates'][0]['expected_orders'] = ['numerator' => '2000000000000000000000', 'denominator' => '100000000000000000000'];
        $selection = (new SalesWindowSelection)->report([$small, $wide])['selections'][0];
        $this->assertSame(2, $selection['selected_window']['width_hours']);
        $this->assertFalse($selection['attempts'][0]['candidates'][0]['meets_minimum']);
        $this->assertTrue($selection['attempts'][1]['candidates'][0]['meets_minimum']);
    }

    public function test_every_stage_candidate_must_meet_the_volume_floor(): void
    {
        $selection = (new SalesWindowSelection)->report([
            $this->band(10, 1, ['25', '19']), $this->band(10, 2, ['20', '20']),
        ])['selections'][0];
        $this->assertSame(2, $selection['selected_window']['width_hours']);
        $this->assertSame([true, false], array_column($selection['attempts'][0]['candidates'], 'meets_minimum'));
        $this->assertSame([true, true], array_column($selection['attempts'][1]['candidates'], 'meets_minimum'));
    }

    public function test_one_expected_order_per_day_abstains_after_all_widths(): void
    {
        $selection = (new SalesWindowSelection)->report([
            $this->band(10, 1, ['1']), $this->band(10, 2, ['1']),
            $this->band(0, 12, ['1']), $this->band(0, 24, ['1']),
        ])['selections'][0];
        $this->assertSame('abstain', $selection['selection_state']);
        $this->assertNull($selection['selected_window']);
        $this->assertSame([1, 2, 12, 24], array_column($selection['attempts'], 'width_hours'));
        $this->assertSame(['expected_order_volume_below_minimum'], $selection['reason_codes']);
    }

    public function test_missing_comparison_history_does_not_reuse_large_raw_estimates(): void
    {
        $bands = [];
        foreach ([1 => 10, 2 => 10, 12 => 0, 24 => 0] as $width => $hour) {
            $band = $this->band($hour, $width, ['1000000']);
            $band['warmup_profile']['structural_state'] = 'insufficient';
            $band['warmup_profile']['reason_codes'] = ['same_weekday_samples_missing'];
            $bands[] = $band;
        }
        $selection = (new SalesWindowSelection)->report($bands)['selections'][0];
        $this->assertNull($selection['selected_window']);
        $this->assertSame(['unknown'], array_values(array_unique(array_column($selection['attempts'], 'volume_state'))));
        $this->assertSame(['same_weekday_samples_missing'], $selection['reason_codes']);
    }

    public function test_invalid_fractions_remain_unknown_without_arithmetic_errors(): void
    {
        foreach ([null, ['numerator' => '0', 'denominator' => '0'], ['numerator' => '-1', 'denominator' => '2'], ['numerator' => '20.0', 'denominator' => '1'], ['numerator' => 20.0, 'denominator' => '1']] as $fraction) {
            $band = $this->band(10, 1, ['20']);
            $band['warmup_profile']['candidates'][0]['expected_orders'] = $fraction;
            $selection = (new SalesWindowSelection)->report([$band])['selections'][0];
            $this->assertNull($selection['selected_window']);
            $this->assertSame('unknown', $selection['attempts'][0]['volume_state']);
            $this->assertContains('expected_order_fraction_invalid', $selection['reason_codes']);
        }
    }

    public function test_minimum_is_an_explicit_positive_integer_string(): void
    {
        $selector = new SalesWindowSelection;
        foreach (['0', '01', '-1', '1.5', '1e3', '', ' 20', "20\n"] as $value) {
            try {
                $selector->report([], $value);
                $this->fail('Invalid minimum must fail closed');
            } catch (\InvalidArgumentException $error) {
                $this->assertSame('sales_window_min_expected_orders_invalid', $error->getMessage());
            }
        }
        $report = $selector->report([$this->band(10, 1, ['10'])], '10');
        $this->assertSame('10', $report['policy']['min_expected_orders']);
        $this->assertSame(1, $report['selections'][0]['selected_window']['width_hours']);
        $this->assertSame('100000000000000000000', $selector->minimum('100000000000000000000'));
    }

    public function test_an_unrelated_high_volume_window_is_not_borrowed_for_the_anchor(): void
    {
        $selection = (new SalesWindowSelection)->report([
            $this->band(11, 1, ['1']), $this->band(12, 2, ['100']),
            $this->band(12, 12, ['200']), $this->band(0, 24, ['300']),
        ])['selections'][0];
        $this->assertSame(24, $selection['selected_window']['width_hours']);
        $this->assertSame('containing_window_missing', $selection['attempts'][1]['reason_codes'][0]);
        $this->assertSame('containing_window_missing', $selection['attempts'][2]['reason_codes'][0]);
    }

    public function test_dst_anchors_preserve_both_repetitions_and_never_invent_the_missing_hour(): void
    {
        foreach (['2026-10-25' => 25, '2026-03-29' => 23] as $date => $count) {
            $bands = [];
            foreach ((new SalesLocalBands)->forDay(CarbonImmutable::parse($date, 'Europe/Berlin'))['bands'] as $band) {
                $bands[] = [
                    ...$band,
                    'target_from' => $band['from'], 'target_to' => $band['to'],
                    'warmup_profile' => ['structural_state' => 'insufficient', 'reason_codes' => ['same_weekday_samples_missing'], 'candidates' => []],
                ];
            }
            $report = (new SalesWindowSelection)->report($bands);
            $this->assertCount($count, $report['selections']);
            $anchors = array_column($report['selections'], 'anchor');
            $this->assertCount($count, array_unique(array_column($anchors, 'target_from')));
            $hourTwo = array_values(array_filter($anchors, fn (array $anchor): bool => $anchor['local_start_hour'] === 2));
            $this->assertCount($count === 25 ? 2 : 0, $hourTwo);
            $this->assertSame(array_fill(0, $count, null), array_column($report['selections'], 'selected_window'));
        }
    }

    private function band(int $hour, int $width, array $expected): array
    {
        $from = CarbonImmutable::parse('2026-08-30', 'UTC')->addHours($hour);

        return [
            'local_start_hour' => $hour, 'width_hours' => $width,
            'occurrence' => 1, 'occurrences' => 1, 'duration_seconds' => $width * 3600,
            'target_from' => $from->toIso8601String(), 'target_to' => $from->addHours($width)->toIso8601String(),
            'warmup_profile' => [
                'structural_state' => 'available', 'reason_codes' => [],
                'candidates' => array_map(fn (string $value): array => ['method' => 'candidate-'.$value, 'expected_orders' => ['numerator' => $value, 'denominator' => '1']], $expected),
            ],
        ];
    }
}
