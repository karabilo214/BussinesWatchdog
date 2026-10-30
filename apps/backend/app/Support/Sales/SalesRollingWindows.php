<?php

namespace App\Support\Sales;

use Carbon\CarbonImmutable;
use DateTimeZone;
use InvalidArgumentException;

class SalesRollingWindows
{
    public const MAX_LAG_SECONDS = 28 * 86400;

    public function lag(string $value): int
    {
        if (! preg_match('/^(0|[1-9][0-9]*)$/D', $value) || bccomp($value, (string) self::MAX_LAG_SECONDS, 0) > 0) {
            throw new InvalidArgumentException('sales_window_lag_invalid');
        }

        return (int) $value;
    }

    public function target(CarbonImmutable $end, int $width, string $timezone): array
    {
        if (! in_array($width, SalesLocalBands::WIDTHS, true)) {
            throw new InvalidArgumentException('sales_window_width_invalid');
        }

        return $this->describe($end->utc()->subHours($width), $end->utc(), $width, $timezone);
    }

    public function historical(array $target, string $endDate, string $timezone): array
    {
        $startDate = CarbonImmutable::parse($endDate, 'UTC')->subDays($target['local_day_span'])->toDateString();
        $starts = $this->points($startDate, substr($target['local_from'], 11, 8), $timezone);
        $ends = $this->points($endDate, substr($target['local_to'], 11, 8), $timezone);
        $reason = match (true) {
            $starts === [] || $ends === [] => 'local_endpoint_absent',
            count($starts) !== $target['start_occurrences'] || count($ends) !== $target['end_occurrences'] => 'endpoint_occurrences_not_comparable',
            default => null,
        };
        if ($reason !== null) {
            return ['window' => null, 'reason' => $reason];
        }
        $from = $starts[$target['start_occurrence'] - 1];
        $to = $ends[$target['end_occurrence'] - 1];
        if ($to - $from !== $target['duration_seconds']) {
            return ['window' => null, 'reason' => 'window_duration_not_comparable'];
        }

        return ['window' => $this->describe(CarbonImmutable::createFromTimestampUTC($from), CarbonImmutable::createFromTimestampUTC($to), $target['width_hours'], $timezone), 'reason' => null];
    }

    private function describe(CarbonImmutable $from, CarbonImmutable $to, int $width, string $timezone): array
    {
        $localFrom = $from->setTimezone($timezone);
        $localTo = $to->setTimezone($timezone);
        $starts = $this->points($localFrom->toDateString(), $localFrom->format('H:i:s'), $timezone);
        $ends = $this->points($localTo->toDateString(), $localTo->format('H:i:s'), $timezone);
        $startPosition = array_search($from->getTimestamp(), $starts, true);
        $endPosition = array_search($to->getTimestamp(), $ends, true);
        if ($startPosition === false || $endPosition === false) {
            throw new InvalidArgumentException('sales_window_local_time_unresolved');
        }

        return [
            'from' => $from->toIso8601String(), 'to' => $to->toIso8601String(),
            'local_from' => $localFrom->toIso8601String(), 'local_to' => $localTo->toIso8601String(),
            'width_hours' => $width, 'duration_seconds' => $to->getTimestamp() - $from->getTimestamp(),
            'local_day_span' => (int) CarbonImmutable::parse($localFrom->toDateString(), 'UTC')->diffInDays(CarbonImmutable::parse($localTo->toDateString(), 'UTC')),
            'start_occurrence' => $startPosition + 1, 'start_occurrences' => count($starts),
            'end_occurrence' => $endPosition + 1, 'end_occurrences' => count($ends),
        ];
    }

    private function points(string $date, string $time, string $timezone): array
    {
        $day = CarbonImmutable::parse($date, $timezone)->startOfDay();
        $start = $day->getTimestamp();
        $end = $day->addDay()->startOfDay()->getTimestamp();
        $wall = CarbonImmutable::parse($date.' '.$time, 'UTC')->getTimestamp();
        $transitions = (new DateTimeZone($timezone))->getTransitions($start, $end) ?: [['ts' => $start, 'offset' => $day->offset]];
        $points = [];
        foreach ($transitions as $index => $transition) {
            $point = $wall - $transition['offset'];
            if ($point >= $transition['ts'] && $point < ($transitions[$index + 1]['ts'] ?? $end)) {
                $points[] = $point;
            }
        }

        return array_values(array_unique($points));
    }
}
