<?php

namespace App\Support\Site;

use App\Models\BillingPrice;
use App\Support\Billing\BillingCatalog;
use App\Support\Billing\FeatureCatalog;
use App\Support\Billing\Paddle\PaddleConfig;

/**
 * The published plans as the pricing page shows them (spec PUBLIC-SUBSCRIPTION-FRONTEND §3): only published versions
 * with a price in the chosen period and currency, placeholder plans only in test mode and marked as examples, no
 * conversion between currencies; a
 * yearly price is shown as the yearly charge with an approximate monthly amount, and the saving only when the same
 * plan has a monthly price in the same currency.
 */
class PricingTable
{
    public function __construct(private readonly BillingCatalog $catalog) {}

    /**
     * @return array{plans: list<array<string, mixed>>, periods: list<string>, period: ?string, currencies: list<string>, currency: ?string, test_mode: bool}
     */
    public function build(string $locale, ?string $period, ?string $currency): array
    {
        $live = PaddleConfig::mode() === 'live';
        $all = array_values(array_filter($this->catalog->plans(), fn (array $plan): bool => ! $plan['placeholder'] || ! $live));
        $plans = array_values(array_filter($all, fn (array $plan): bool => $this->bases($plan) !== []));
        $individual = array_values(array_filter($all, fn (array $plan): bool => $plan['contact_sales'] && $this->bases($plan) === []));
        $periods = [];

        foreach ($plans as $plan) {
            foreach ($this->bases($plan) as $price) {
                $periods[self::period($price)] = true;
            }
        }

        $periods = array_keys($periods);
        usort($periods, fn (string $left, string $right): int => self::periodOrder($left) <=> self::periodOrder($right));
        $period = in_array($period, $periods, true) ? $period : ($periods[0] ?? null);
        $currencies = [];

        foreach ($plans as $plan) {
            foreach ($this->bases($plan) as $price) {
                if (self::period($price) === $period) {
                    $currencies[$price['currency']] = true;
                }
            }
        }

        $currencies = array_keys($currencies);
        sort($currencies);
        $currency = in_array($currency, $currencies, true) ? $currency : (in_array('EUR', $currencies, true) ? 'EUR' : ($currencies[0] ?? null));
        $rows = [];

        foreach ($plans as $plan) {
            $base = $this->find($plan, BillingPrice::KIND_BASE, $period, $currency);

            if ($base !== null) {
                $rows[] = $this->row($plan, $base, $locale);
            }
        }

        foreach ($individual as $plan) {
            $rows[] = $this->row($plan, null, $locale);
        }

        return ['plans' => $rows, 'periods' => $periods, 'period' => $period, 'currencies' => $currencies, 'currency' => $currency, 'test_mode' => ! $live];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>|null  $base  null for a package with an individual price
     * @return array<string, mixed>
     */
    private function row(array $plan, ?array $base, string $locale): array
    {
        $format = fn (string $minor): string => $base === null ? '' : MoneyFormat::format($minor, (int) $base['currency_exponent'], $base['currency'], $locale);
        $count = (int) ($base['interval_count'] ?? 1);
        $monthly = null;
        $saving = null;

        if ($base !== null && $base['interval'] === 'year' && $count === 1) {
            $amount = (int) $base['amount_minor'];
            $monthly = $format((string) intdiv($amount + 6, 12));
            $month = $this->find($plan, BillingPrice::KIND_BASE, 'month:1', $base['currency']);

            if ($month !== null) {
                $twelve = (int) $month['amount_minor'] * 12;
                $saving = $twelve > $amount ? intdiv(($twelve - $amount) * 100, $twelve) : null;
                $saving = $saving === 0 ? null : $saving;
            }
        }

        $extra = $base === null ? null : $this->find($plan, BillingPrice::KIND_EXTRA_STORE, self::period($base), $base['currency']);
        $limits = [];

        foreach ([FeatureCatalog::MAX_ORDERS_PER_MONTH, FeatureCatalog::MAX_ACTIVE_STORES, FeatureCatalog::MIN_CHECK_INTERVAL_MINUTES, FeatureCatalog::MANUAL_RUNS_PER_DAY, FeatureCatalog::MONTHLY_EVENT_BUDGET, FeatureCatalog::RETENTION_DAYS] as $key) {
            if (! array_key_exists($key, $plan['entitlements']['limits'])) {
                continue;
            }

            $value = $plan['entitlements']['limits'][$key];
            $limits[] = [
                'label' => trans("site.limits.{$key}", [], $locale),
                'value' => $value === null ? trans('site.limits.unlimited', [], $locale) : trans("site.limit_values.{$key}", ['n' => number_format($value, 0, '', $locale === 'en' ? ',' : ' ')], $locale),
            ];
        }

        return [
            'code' => $plan['plan_code'],
            'placeholder' => (bool) $plan['placeholder'],
            'name' => $plan['name'][$locale] ?? $plan['name']['en'] ?? $plan['plan_code'],
            'description' => $plan['description'][$locale] ?? $plan['description']['en'] ?? null,
            'individual' => $base === null,
            'price' => $base === null ? null : $format((string) $base['amount_minor']),
            'per' => $base === null ? null : self::perLabel($base['interval'], $count, $locale),
            'monthly' => $monthly,
            'saving' => $saving,
            'extra_store' => $extra === null ? null : $format((string) $extra['amount_minor']),
            'tax' => $base === null ? null : trans('site.pricing.tax.'.$base['tax_behavior'], [], $locale),
            'trial_days' => (int) ($plan['trial_days'] ?? 0),
            'features' => array_map(fn (string $feature): string => trans("site.features.{$feature}", [], $locale), $plan['entitlements']['features']),
            'limits' => $limits,
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return list<array<string, mixed>>
     */
    private function bases(array $plan): array
    {
        return array_values(array_filter($plan['prices'], fn (array $price): bool => $price['kind'] === BillingPrice::KIND_BASE));
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>|null
     */
    private function find(array $plan, string $kind, ?string $period, ?string $currency): ?array
    {
        foreach ($plan['prices'] as $price) {
            if ($price['kind'] === $kind && self::period($price) === $period && $price['currency'] === $currency) {
                return $price;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $price
     */
    public static function period(array $price): string
    {
        return $price['interval'].':'.$price['interval_count'];
    }

    public static function periodLabel(string $period, string $locale): string
    {
        [$interval, $count] = explode(':', $period) + [1 => '1'];

        return (int) $count === 1 ? trans("site.pricing.period.{$interval}", [], $locale) : trans_choice("site.pricing.period_many.{$interval}", (int) $count, ['n' => $count], $locale);
    }

    private static function perLabel(string $interval, int $count, string $locale): string
    {
        return $count === 1 ? trans("site.pricing.per.{$interval}", [], $locale) : trans_choice("site.pricing.per_many.{$interval}", $count, ['n' => $count], $locale);
    }

    private static function periodOrder(string $period): array
    {
        [$interval, $count] = explode(':', $period) + [1 => '1'];

        return [['day' => 0, 'week' => 1, 'month' => 2, 'year' => 3][$interval] ?? 4, (int) $count];
    }
}
