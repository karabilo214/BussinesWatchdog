<?php

namespace Tests\Unit\Sales;

use App\Support\Sales\SalesLocalBands;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class SalesLocalBandsTest extends TestCase
{
    public function test_fallback_hour_has_two_distinct_utc_intervals_and_offsets(): void
    {
        foreach (['Europe/Berlin', 'Europe/Vienna', 'Europe/Zurich'] as $timezone) {
            $layout = $this->layout('2026-10-25', $timezone);
            $bands = $this->bands($layout, 1, 2);
            $this->assertSame([1, 2], array_column($bands, 'occurrence'));
            $this->assertSame([2, 2], array_column($bands, 'occurrences'));
            $this->assertSame(['2026-10-25T00:00:00+00:00', '2026-10-25T01:00:00+00:00'], array_column($bands, 'from'));
            $this->assertSame(['2026-10-25T01:00:00+00:00', '2026-10-25T02:00:00+00:00'], array_column($bands, 'to'));
            $this->assertSame(['2026-10-25T02:00:00+02:00', '2026-10-25T02:00:00+01:00'], array_column($bands, 'local_from'));
            $this->assertSame([3600, 3600], array_column($bands, 'duration_seconds'));
            $this->assertCount(40, $layout['bands']);
            $this->assertSame([], $layout['missing_bands']);
        }
    }

    public function test_spring_gap_has_no_invented_hour_and_keeps_actual_wide_durations(): void
    {
        $layout = $this->layout('2026-03-29');
        $this->assertSame([], $this->bands($layout, 1, 2));
        $this->assertSame([['local_start_hour' => 2, 'width_hours' => 1, 'reason' => 'local_band_absent']], $layout['missing_bands']);
        $this->assertSame('2026-03-29T01:00:00+00:00', $this->bands($layout, 1, 3)[0]['from']);
        $this->assertSame(3600, $this->bands($layout, 2, 2)[0]['duration_seconds']);
        $this->assertSame(39600, $this->bands($layout, 12, 0)[0]['duration_seconds']);
        $this->assertSame(82800, $this->bands($layout, 24, 0)[0]['duration_seconds']);
        $this->assertCount(38, $layout['bands']);
    }

    public function test_half_hour_transitions_keep_partial_and_repeated_intervals(): void
    {
        $fall = $this->layout('2026-04-05', 'Australia/Lord_Howe');
        $this->assertSame([3600, 1800], array_column($this->bands($fall, 1, 1), 'duration_seconds'));
        $this->assertSame(['2026-04-04T14:00:00+00:00', '2026-04-04T15:00:00+00:00'], array_column($this->bands($fall, 1, 1), 'from'));
        $this->assertSame('2026-04-04T15:30:00+00:00', $this->bands($fall, 1, 2)[0]['from']);
        $spring = $this->layout('2026-10-04', 'Australia/Lord_Howe');
        $this->assertSame(1800, $this->bands($spring, 1, 2)[0]['duration_seconds']);
        $this->assertSame('2026-10-04T02:30:00+11:00', $this->bands($spring, 1, 2)[0]['local_from']);
        $this->assertSame([], $spring['missing_bands']);
    }

    public function test_every_width_partitions_the_real_day_once_without_gaps_or_overlaps(): void
    {
        foreach ([
            ['2026-10-30', 'Europe/Berlin'], ['2026-10-25', 'Europe/Berlin'], ['2026-03-29', 'Europe/Berlin'],
            ['2027-10-31', 'Europe/Berlin'], ['2027-03-28', 'Europe/Berlin'],
            ['2026-04-05', 'Australia/Lord_Howe'], ['2026-10-04', 'Australia/Lord_Howe'],
            ['2026-09-06', 'America/Santiago'],
            ['2026-10-30', 'Asia/Kathmandu'], ['2026-10-30', '+05:45'], ['2026-10-30', 'UTC'],
        ] as [$date, $timezone]) {
            $day = CarbonImmutable::parse($date, $timezone);
            $layout = (new SalesLocalBands)->forDay($day);
            foreach (SalesLocalBands::WIDTHS as $width) {
                $bands = array_values(array_filter($layout['bands'], fn (array $band): bool => $band['width_hours'] === $width));
                usort($bands, fn (array $a, array $b): int => strcmp($a['from'], $b['from']));
                $cursor = $day->utc()->toIso8601String();
                foreach ($bands as $band) {
                    $this->assertSame($cursor, $band['from'], "$date $timezone width=$width");
                    $this->assertGreaterThan(0, $band['duration_seconds']);
                    $cursor = $band['to'];
                }
                $this->assertSame($day->addDay()->startOfDay()->utc()->toIso8601String(), $cursor);
                $this->assertSame($day->addDay()->startOfDay()->getTimestamp() - $day->getTimestamp(), array_sum(array_column($bands, 'duration_seconds')));
            }
        }
    }

    public function test_quarter_hour_offset_and_non_midnight_input_use_local_midnight(): void
    {
        $layout = (new SalesLocalBands)->forDay(CarbonImmutable::parse('2026-10-30 13:17:29', 'Asia/Kathmandu'));
        $this->assertSame('2026-10-29T18:15:00+00:00', $this->bands($layout, 24, 0)[0]['from']);
        $this->assertSame('2026-10-30T04:15:00+00:00', $this->bands($layout, 1, 10)[0]['from']);
        $this->assertCount(39, $layout['bands']);
    }

    private function layout(string $date, string $timezone = 'Europe/Berlin'): array
    {
        return (new SalesLocalBands)->forDay(CarbonImmutable::parse($date, $timezone));
    }

    private function bands(array $layout, int $width, int $hour): array
    {
        return array_values(array_filter($layout['bands'], fn (array $band): bool => $band['width_hours'] === $width && $band['local_start_hour'] === $hour));
    }
}
