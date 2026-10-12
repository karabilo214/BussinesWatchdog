<?php

namespace App\Support\Billing\Paddle;

use App\Models\BillingPlanVersion;
use App\Models\BillingPrice;
use App\Support\Billing\FeatureCatalog;

/**
 * Creates the Paddle product of a plan (shared by its versions) and its prices for every local Paddle price of the current mode that has
 * no Paddle id yet. Each id is stored right after its create call, so a rerun continues where it stopped.
 */
class PaddleCatalogSync
{
    public function __construct(private readonly PaddleClient $client) {}

    /**
     * @return list<array{price_id: string, provider_price_ref: string}>
     */
    public function sync(?string $planVersionId = null): array
    {
        $created = [];
        $prices = BillingPrice::query()
            ->when($planVersionId !== null, fn ($query) => $query->where('plan_version_id', $planVersionId))
            ->where('provider', PaddleConfig::PROVIDER)
            ->where('provider_account_ref', PaddleConfig::accountRef())
            ->where('mode', PaddleConfig::mode())
            ->whereNull('provider_price_ref')
            ->where('state', '!=', 'retired')
            ->orderBy('created_at')
            ->get();

        foreach ($prices as $price) {
            /** @var BillingPlanVersion $version */
            $version = BillingPlanVersion::query()->with('plan')->findOrFail($price->plan_version_id);
            $productRef = $price->provider_product_ref ?? $this->productFor($version);
            $result = $this->client->post('/prices', [
                'product_id' => $productRef,
                'description' => $version->plan->code.' v'.$version->version.' '.$price->price_kind.' every '.$price->billing_interval_count.' '.$price->billing_interval,
                'unit_price' => ['amount' => (string) $price->amount_minor, 'currency_code' => $price->currency],
                'billing_cycle' => ['interval' => $price->billing_interval, 'frequency' => $price->billing_interval_count],
                'quantity' => $price->price_kind === BillingPrice::KIND_EXTRA_STORE
                    ? ['minimum' => 1, 'maximum' => max(1, (int) ($version->entitlements['limits'][FeatureCatalog::MAX_EXTRA_STORES] ?? 100))]
                    : ['minimum' => 1, 'maximum' => 1],
                'tax_mode' => 'account_setting',
                'custom_data' => ['plan_version_id' => $version->id, 'price_id' => $price->id, 'price_kind' => $price->price_kind],
            ]);
            $ref = (string) $result['data']['id'];
            $price->forceFill(['provider_product_ref' => $productRef, 'provider_price_ref' => $ref])->save();
            $created[] = ['price_id' => $price->id, 'provider_price_ref' => $ref];
        }

        return $created;
    }

    private function productFor(BillingPlanVersion $version): string
    {
        $existing = BillingPrice::query()
            ->whereIn('plan_version_id', BillingPlanVersion::query()->where('plan_id', $version->plan_id)->select('id'))
            ->where('provider', PaddleConfig::PROVIDER)
            ->where('provider_account_ref', PaddleConfig::accountRef())
            ->where('mode', PaddleConfig::mode())
            ->whereNotNull('provider_product_ref')
            ->value('provider_product_ref');

        if (is_string($existing)) {
            return $existing;
        }

        $name = $version->localized_content['name']['en'] ?? $version->plan->code;
        $result = $this->client->post('/products', [
            'name' => 'Business Watchdog '.$name,
            'tax_category' => 'saas',
            'custom_data' => ['plan_code' => $version->plan->code, 'plan_version_id' => $version->id],
        ]);
        $ref = (string) $result['data']['id'];
        BillingPrice::query()
            ->where('plan_version_id', $version->id)
            ->where('provider', PaddleConfig::PROVIDER)
            ->where('provider_account_ref', PaddleConfig::accountRef())
            ->where('mode', PaddleConfig::mode())
            ->whereNull('provider_product_ref')
            ->update(['provider_product_ref' => $ref]);

        return $ref;
    }
}
