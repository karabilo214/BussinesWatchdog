<?php

namespace App\Support\Sales;

use App\Models\Store;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

class SalesWarmup
{
    public function __construct(private readonly SalesCoverage $coverage) {}

    public function report(Store $store, string $mode, CarbonImmutable $asOf): array
    {
        if ($asOf->greaterThan(CarbonImmutable::now())) {
            throw new InvalidArgumentException('sales_warmup_future_invalid');
        }

        $end = $asOf->setTimezone($store->timezone)->startOfDay();
        $days = [];
        for ($day = $end->subDays(28); $day->lessThan($end); $day = $day->addDay()) {
            $days[] = [
                'date' => $day->toDateString(),
                'from' => $day->utc()->toIso8601String(),
                'to' => $day->addDay()->utc()->toIso8601String(),
            ];
        }

        $metrics = $this->coverage->forBuckets($store, $mode, $days, observedOnly: true);
        $eligible = 0;
        $calendarStates = [];
        foreach ($days as $index => &$day) {
            $metric = $metrics[$index];
            $day['coverage'] = $metric['orders_created'];
            $day['eligible'] = $metric['baseline_eligible'];
            $day['exclusions'] = $metric['baseline_exclusions'];
            $day['calendar_state'] = $metric['calendar_state'];
            $day['holiday_codes'] = $metric['holiday_codes'];
            $day['comparison_weekday'] = $metric['comparison_weekday'];
            $calendarStates[] = $metric['calendar_state'];
            $eligible += (int) $day['eligible'];
        }
        unset($day);

        $stage = match (true) {
            $eligible >= 28 => 'full',
            $eligible >= 14 => 'major_drop',
            $eligible >= 7 => 'stoppage_only',
            default => 'collecting',
        };
        $next = match ($stage) {
            'collecting' => 7,
            'stoppage_only' => 14,
            'major_drop' => 28,
            default => null,
        };

        return [
            'metric' => 'orders_created',
            'calendar' => app(GermanSalesCalendar::class)->configuration($store),
            'status' => 'diagnostic_only',
            'as_of' => $asOf->utc()->toIso8601String(),
            'eligible_days' => $eligible,
            'stage' => $stage,
            'next_stage_at_days' => $next,
            'additional_eligible_days_required' => $next === null ? 0 : $next - $eligible,
            'baseline_state' => 'unknown',
            'alerts_enabled' => false,
            'reason_codes' => [...(in_array('not_configured', $calendarStates, true) ? ['holiday_calendar_not_configured'] : []), ...(in_array('out_of_range', $calendarStates, true) ? ['holiday_calendar_out_of_range'] : []), 'thresholds_not_calibrated', 'payment_history_coverage_unknown'],
            'days' => $days,
        ];
    }
}
