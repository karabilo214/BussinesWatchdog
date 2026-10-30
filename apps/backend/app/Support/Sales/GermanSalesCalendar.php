<?php

namespace App\Support\Sales;

use App\Models\Store;
use App\Support\Tenancy\TenantContext;
use InvalidArgumentException;

class GermanSalesCalendar
{
    public const DATASET_VERSION = 'de-2026-2027-v1';

    public const REGIONS = ['DE-BW', 'DE-BY', 'DE-BE', 'DE-BB', 'DE-HB', 'DE-HH', 'DE-HE', 'DE-MV', 'DE-NI', 'DE-NW', 'DE-RP', 'DE-SL', 'DE-SN', 'DE-ST', 'DE-SH', 'DE-TH'];

    private const FIXED = ['new_year' => '01-01', 'epiphany' => '01-06', 'womens_day' => '03-08', 'labour_day' => '05-01', 'augsburg_peace' => '08-08', 'assumption' => '08-15', 'childrens_day' => '09-20', 'unity' => '10-03', 'reformation' => '10-31', 'all_saints' => '11-01', 'christmas' => '12-25', 'boxing_day' => '12-26'];

    private const REGIONAL = [
        'epiphany' => ['DE-BW', 'DE-BY', 'DE-ST'], 'womens_day' => ['DE-BE', 'DE-MV'],
        'easter_sunday' => ['DE-BB'], 'pentecost_sunday' => ['DE-BB'],
        'corpus_christi' => ['DE-BW', 'DE-BY', 'DE-HE', 'DE-NW', 'DE-RP', 'DE-SL'],
        'assumption' => ['DE-SL'], 'augsburg_peace' => [], 'childrens_day' => ['DE-TH'],
        'reformation' => ['DE-BB', 'DE-HB', 'DE-HH', 'DE-MV', 'DE-NI', 'DE-SN', 'DE-ST', 'DE-SH', 'DE-TH'],
        'all_saints' => ['DE-BW', 'DE-BY', 'DE-NW', 'DE-RP', 'DE-SL'], 'repentance' => ['DE-SN'],
    ];

    private readonly array $years;

    public function __construct(private readonly TenantContext $tenants)
    {
        $this->years = require database_path('data/sales-holidays-de.php');
    }

    public static function localOptions(?string $region): array
    {
        return match ($region) {
            'DE-BY' => ['assumption', 'augsburg_peace'],
            'DE-SN', 'DE-TH' => ['corpus_christi'],
            default => [],
        };
    }

    public function configuration(Store $store): array
    {
        if ($store->tenant_id !== $this->tenants->requireTenantId('sales.calendar.read')) {
            throw new InvalidArgumentException('sales_calendar_scope_invalid');
        }
        $saved = $store->settings['sales_calendar'] ?? [];

        return [
            'region' => $saved['region'] ?? null,
            'local_holidays' => $saved['local_holidays'] ?? [],
            'version' => $saved['version'] ?? 1,
            'dataset_version' => self::DATASET_VERSION,
            'supported_years' => array_keys($this->years),
        ];
    }

    public function classify(string $date, array $configuration): array
    {
        $region = $configuration['region'];
        if (! in_array($region, self::REGIONS, true) || array_diff($configuration['local_holidays'], self::localOptions($region)) !== []) {
            return ['state' => 'not_configured', 'codes' => []];
        }
        $year = (int) substr($date, 0, 4);
        if (! isset($this->years[$year])) {
            return ['state' => 'out_of_range', 'codes' => []];
        }
        $codes = [];
        foreach ([...self::FIXED, ...$this->years[$year]] as $code => $monthDay) {
            if (substr($date, 5) !== $monthDay) {
                continue;
            }
            if (! array_key_exists($code, self::REGIONAL) || in_array($region, self::REGIONAL[$code], true) || in_array($code, $configuration['local_holidays'], true)) {
                $codes[] = $code;
            }
        }
        sort($codes);

        return ['state' => 'ready', 'codes' => $codes];
    }
}
