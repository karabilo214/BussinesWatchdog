<?php

namespace App\Support\Sales;

use Carbon\CarbonImmutable;
use DateTimeZone;

class SalesLocalBands
{
    public const WIDTHS = [1, 2, 12, 24];

    public function forDay(CarbonImmutable $day): array
    {
        $day = $day->startOfDay();
        $start = $day->getTimestamp();
        $end = $day->addDay()->startOfDay()->getTimestamp();
        $wallStart = CarbonImmutable::parse($day->toDateString(), 'UTC')->getTimestamp();
        $transitions = (new DateTimeZone($day->timezoneName))->getTransitions($start, $end);
        $transitions = $transitions ?: [['ts' => $start, 'offset' => $day->offset]];
        $bands = [];
        $missing = [];

        foreach (self::WIDTHS as $width) {
            for ($hour = 0; $hour < 24; $hour += $width) {
                $pieces = [];
                foreach ($transitions as $index => $transition) {
                    $from = max($transition['ts'], $wallStart + $hour * 3600 - $transition['offset']);
                    $to = min($transitions[$index + 1]['ts'] ?? $end, $wallStart + ($hour + $width) * 3600 - $transition['offset']);
                    if ($from >= $to) {
                        continue;
                    }
                    $last = count($pieces) - 1;
                    if ($last >= 0 && $pieces[$last]['to'] === $from && ($width > 1 || $transition['offset'] >= $pieces[$last]['offset'])) {
                        $pieces[$last]['to'] = $to;
                        $pieces[$last]['offset'] = $transition['offset'];
                    } else {
                        $pieces[] = ['from' => $from, 'to' => $to, 'offset' => $transition['offset']];
                    }
                }
                if ($pieces === []) {
                    $missing[] = ['local_start_hour' => $hour, 'width_hours' => $width, 'reason' => 'local_band_absent'];
                }
                foreach ($pieces as $index => $piece) {
                    $from = CarbonImmutable::createFromTimestampUTC($piece['from']);
                    $to = CarbonImmutable::createFromTimestampUTC($piece['to']);
                    $bands[] = [
                        'local_start_hour' => $hour,
                        'width_hours' => $width,
                        'occurrence' => $index + 1,
                        'occurrences' => count($pieces),
                        'from' => $from->toIso8601String(),
                        'to' => $to->toIso8601String(),
                        'local_from' => $from->setTimezone($day->timezone)->toIso8601String(),
                        'local_to' => $to->setTimezone($day->timezone)->toIso8601String(),
                        'duration_seconds' => $piece['to'] - $piece['from'],
                    ];
                }
            }
        }

        return ['bands' => $bands, 'missing_bands' => $missing];
    }
}
