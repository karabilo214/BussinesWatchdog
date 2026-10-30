<?php

namespace Tests\Unit\Sales;

use App\Support\Sales\SalesRollingWindows;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class SalesRollingWindowsTest extends TestCase
{
    public function test_elapsed_windows_preserve_seconds_and_quarter_hour_offset(): void
    {
        $geometry = new SalesRollingWindows;
        $end = CarbonImmutable::parse('2026-08-30T12:29:56Z');
        foreach ([1, 2, 12, 24] as $width) {
            $window = $geometry->target($end, $width, 'Asia/Kathmandu');
            $this->assertSame($width * 3600, $window['duration_seconds']);
            $this->assertSame('2026-08-30T12:29:56+00:00', $window['to']);
            $this->assertSame('2026-08-30T18:14:56+05:45', $window['local_to']);
            $this->assertSame('56', substr($window['from'], 17, 2));
        }
    }

    public function test_cross_midnight_history_uses_both_local_dates_without_rounding(): void
    {
        $geometry = new SalesRollingWindows;
        $target = $geometry->target(CarbonImmutable::parse('2026-08-31T00:20:00+02:00'), 2, 'Europe/Berlin');
        $this->assertSame(1, $target['local_day_span']);
        $history = $geometry->historical($target, '2026-08-24', 'Europe/Berlin');
        $this->assertNull($history['reason']);
        $this->assertSame('2026-08-23T20:20:00+00:00', $history['window']['from']);
        $this->assertSame('2026-08-23T22:20:00+00:00', $history['window']['to']);
    }

    public function test_repeated_endpoint_occurrences_are_not_replaced_by_normal_hours(): void
    {
        $geometry = new SalesRollingWindows;
        $first = $geometry->target(CarbonImmutable::parse('2026-10-25T00:30:00Z'), 1, 'Europe/Berlin');
        $second = $geometry->target(CarbonImmutable::parse('2026-10-25T01:30:00Z'), 1, 'Europe/Berlin');
        $this->assertSame(1, $first['end_occurrence']);
        $this->assertSame(2, $second['end_occurrence']);
        $this->assertSame(2, $second['end_occurrences']);
        $this->assertSame('2026-10-25T02:30:00+02:00', $second['local_from']);
        $this->assertSame('2026-10-25T02:30:00+01:00', $second['local_to']);
        foreach ([$first, $second] as $target) {
            $this->assertSame('endpoint_occurrences_not_comparable', $geometry->historical($target, '2026-10-18', 'Europe/Berlin')['reason']);
        }
    }

    public function test_spring_transition_keeps_unaffected_windows_and_rejects_changed_duration(): void
    {
        $geometry = new SalesRollingWindows;
        $end = CarbonImmutable::parse('2026-03-29T02:30:00Z');
        $hour = $geometry->historical($geometry->target($end, 1, 'Europe/Berlin'), '2026-03-22', 'Europe/Berlin');
        $this->assertNull($hour['reason']);
        $this->assertSame('2026-03-22T02:30:00+00:00', $hour['window']['from']);
        $this->assertSame('2026-03-22T03:30:00+00:00', $hour['window']['to']);
        $wide = $geometry->historical($geometry->target($end, 2, 'Europe/Berlin'), '2026-03-22', 'Europe/Berlin');
        $this->assertSame('window_duration_not_comparable', $wide['reason']);
        $normal = $geometry->target(CarbonImmutable::parse('2026-04-05T02:30:00+02:00'), 1, 'Europe/Berlin');
        $this->assertSame('local_endpoint_absent', $geometry->historical($normal, '2026-03-29', 'Europe/Berlin')['reason']);
    }

    public function test_half_hour_shift_does_not_invent_equal_elapsed_duration(): void
    {
        $geometry = new SalesRollingWindows;
        $target = $geometry->target(CarbonImmutable::parse('2026-10-04T02:45:00+11:00'), 1, 'Australia/Lord_Howe');
        $this->assertSame('2026-10-04T01:15:00+10:30', $target['local_from']);
        $this->assertSame('window_duration_not_comparable', $geometry->historical($target, '2026-09-27', 'Australia/Lord_Howe')['reason']);
    }

    public function test_lag_is_explicit_and_bounded_by_the_diagnostic_history_horizon(): void
    {
        $geometry = new SalesRollingWindows;
        $this->assertSame(0, $geometry->lag('0'));
        $this->assertSame(300, $geometry->lag('300'));
        $this->assertSame(2419200, $geometry->lag('2419200'));
        foreach (['', '-1', '01', '1.5', '1e3', '2419201', '100000000000000000000'] as $value) {
            try {
                $geometry->lag($value);
                $this->fail('Invalid lag must fail closed');
            } catch (\InvalidArgumentException $error) {
                $this->assertSame('sales_window_lag_invalid', $error->getMessage());
            }
        }
    }
}
