<?php

namespace App\Console\Commands;

use App\Models\BillingPlan;
use App\Models\BillingPlanVersion;
use App\Models\BillingPrice;
use App\Support\Billing\FeatureCatalog;
use App\Support\Billing\Paddle\PaddleConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Draft plans to build the public pricing, the owner panel and the provider adapter against (ADR 0029). They are
 * placeholders: nothing is published and no price exists until the owner sets contents and prices.
 */
class SeedPlaceholderPlans extends Command
{
    protected $signature = 'billing:seed-placeholder-plans {--test-prices : Also add placeholder Paddle sandbox prices (EUR, monthly)}';

    protected $description = 'Create draft placeholder plans (Free / Start / Pro) if they do not exist';

    public const PLANS = [
        'free' => ['name' => 'Free', 'features' => [FeatureCatalog::PAYMENT_ATTEMPTS_SERVER], 'limits' => [FeatureCatalog::MAX_ACTIVE_STORES => 1, FeatureCatalog::MAX_EXTRA_STORES => 0, FeatureCatalog::MIN_CHECK_INTERVAL_MINUTES => 60, FeatureCatalog::RETENTION_DAYS => 30, FeatureCatalog::MANUAL_RUNS_PER_DAY => 1]],
        'start' => ['name' => 'Start', 'features' => [FeatureCatalog::PAYMENT_ATTEMPTS_SERVER, FeatureCatalog::CHECKOUT_MONITORING, FeatureCatalog::MONEY_RECONCILIATION], 'limits' => [FeatureCatalog::MAX_ACTIVE_STORES => 1, FeatureCatalog::MAX_EXTRA_STORES => 4, FeatureCatalog::MIN_CHECK_INTERVAL_MINUTES => 15, FeatureCatalog::RETENTION_DAYS => 180, FeatureCatalog::MANUAL_RUNS_PER_DAY => 10]],
        'pro' => ['name' => 'Pro', 'features' => FeatureCatalog::FEATURES, 'limits' => [FeatureCatalog::MAX_ACTIVE_STORES => 3, FeatureCatalog::MAX_EXTRA_STORES => 47, FeatureCatalog::MIN_CHECK_INTERVAL_MINUTES => 5, FeatureCatalog::RETENTION_DAYS => 365, FeatureCatalog::MANUAL_RUNS_PER_DAY => 50]],
    ];

    /** Sandbox-only placeholder amounts in cents; not a price proposal. */
    public const TEST_PRICES = [
        'start' => [BillingPrice::KIND_BASE => 1900, BillingPrice::KIND_EXTRA_STORE => 900],
        'pro' => [BillingPrice::KIND_BASE => 4900, BillingPrice::KIND_EXTRA_STORE => 700],
    ];

    public function handle(): int
    {
        foreach (self::PLANS as $code => $plan) {
            DB::transaction(function () use ($code, $plan): void {
                $model = BillingPlan::query()->firstOrCreate(['code' => $code], ['state' => BillingPlan::STATE_DRAFT]);

                if ($model->versions()->exists()) {
                    return;
                }

                BillingPlanVersion::query()->create([
                    'plan_id' => $model->id,
                    'version' => 1,
                    'state' => BillingPlan::STATE_DRAFT,
                    'localized_content' => ['placeholder' => true, 'name' => ['ru' => $plan['name'], 'en' => $plan['name'], 'de' => $plan['name']]],
                    'entitlements' => FeatureCatalog::normalize(['features' => $plan['features'], 'limits' => $plan['limits']]),
                    'trial_days' => 14,
                ]);
            });
            $this->line($code.' draft');
        }

        if ($this->option('test-prices')) {
            $this->testPrices();
        }

        return self::SUCCESS;
    }

    private function testPrices(): void
    {
        foreach (self::TEST_PRICES as $code => $amounts) {
            /** @var BillingPlanVersion $version */
            $version = BillingPlanVersion::query()->whereHas('plan', fn ($query) => $query->where('code', $code))->orderByDesc('version')->firstOrFail();

            foreach ($amounts as $kind => $amount) {
                BillingPrice::query()->firstOrCreate([
                    'plan_version_id' => $version->id,
                    'price_kind' => $kind,
                    'currency' => 'EUR',
                    'billing_interval' => 'month',
                    'mode' => 'test',
                ], [
                    'provider' => PaddleConfig::PROVIDER,
                    'provider_account_ref' => PaddleConfig::accountRef(),
                    'currency_exponent' => 2,
                    'amount_minor' => $amount,
                    'tax_behavior' => 'provider_calculated',
                    'state' => BillingPlan::STATE_DRAFT,
                ]);
            }

            $this->line($code.' test prices');
        }
    }
}
