<?php

namespace App\Support\Sales;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Throwable;

class SalesReportTime
{
    public function parse(mixed $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:\d{2})$/', $value)) {
            throw new InvalidArgumentException('sales_report_time_invalid');
        }
        try {
            $date = CarbonImmutable::parse($value);
            if ($date->format('Y-m-d\TH:i:sP') !== str_replace('Z', '+00:00', $value)) {
                throw new InvalidArgumentException;
            }

            return $date;
        } catch (Throwable $error) {
            throw new InvalidArgumentException('sales_report_time_invalid', previous: $error);
        }
    }
}
