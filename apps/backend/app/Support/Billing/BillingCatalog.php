<?php

namespace App\Support\Billing;

use App\Models\BillingPlan;
use App\Models\BillingPlanVersion;
use App\Models\BillingPrice;
use App\Support\Billing\Paddle\PaddleConfig;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class BillingCatalog
{
    /**
     * @return list<string>
     */
    public static function sellableStates(): array
    {
        return [BillingPlan::STATE_PUBLISHED];
    }

    /**
     * @return Builder<BillingPrice>
     */
    public function prices(): Builder
    {
        return BillingPrice::query()
            ->where('provider', PaddleConfig::PROVIDER)
            ->where('provider_account_ref', PaddleConfig::accountRef())
            ->where('mode', PaddleConfig::mode())
            ->whereNotNull('provider_price_ref')
            ->whereIn('state', self::sellableStates());
    }

    /**
     * @return list<BillingPlanVersion>
     */
    public function versions(): array
    {
        $states = self::sellableStates();

        return BillingPlanVersion::query()
            ->with('plan')
            ->whereIn('state', $states)
            ->whereHas('plan', fn ($query) => $query->whereIn('state', $states))
            ->orderByDesc('version')
            ->get()
            ->unique('plan_id')
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function plans(?string $tenantId = null): array
    {
        $versions = $this->versions();
        $prices = $this->prices()
            ->whereIn('plan_version_id', array_map(fn (BillingPlanVersion $version): string => $version->id, $versions))
            ->orderBy('price_kind')
            ->orderBy('currency')
            ->get()
            ->groupBy('plan_version_id');

        $plans = array_map(fn (BillingPlanVersion $version): array => $this->withDiscount([
            'plan_code' => $version->plan->code,
            'plan_version_id' => $version->id,
            'name' => $version->localized_content['name'] ?? [],
            'description' => $version->localized_content['description'] ?? [],
            'placeholder' => (bool) ($version->localized_content['placeholder'] ?? false),
            'trial_days' => $version->trial_days,
            'entitlements' => FeatureCatalog::normalize($version->entitlements),
            'prices' => ($prices[$version->id] ?? collect())->map(fn (BillingPrice $price): array => [
                'id' => $price->id,
                'kind' => $price->price_kind,
                'currency' => $price->currency,
                'currency_exponent' => $price->currency_exponent,
                'amount_minor' => (string) $price->amount_minor,
                'interval' => $price->billing_interval,
                'interval_count' => $price->billing_interval_count,
                'tax_behavior' => $price->tax_behavior,
            ])->values()->all(),
        ], $tenantId, $prices[$version->id] ?? collect()), $versions);

        usort($plans, fn (array $left, array $right): int => [$this->basePrice($left) === null, $this->basePrice($left), $left['plan_code']] <=> [$this->basePrice($right) === null, $this->basePrice($right), $right['plan_code']]);

        return $plans;
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  Collection<int, BillingPrice>  $prices
     * @return array<string, mixed>
     */
    private function withDiscount(array $plan, ?string $tenantId, Collection $prices): array
    {
        $base = $prices->firstWhere('price_kind', BillingPrice::KIND_BASE);
        $discount = $tenantId === null || $base === null ? null : app(DiscountResolver::class)->bestAutomatic($tenantId, $base);

        return $plan + ['discount' => $discount === null ? null : DiscountResolver::dto($discount)];
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function basePrice(array $plan): ?int
    {
        foreach ($plan['prices'] as $price) {
            if ($price['kind'] === BillingPrice::KIND_BASE) {
                return (int) $price['amount_minor'];
            }
        }

        return null;
    }
}
