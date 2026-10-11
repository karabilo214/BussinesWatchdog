<?php

namespace App\Console\Commands;

use App\Models\BillingPlan;
use App\Models\BillingPlanVersion;
use App\Support\Billing\FeatureCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Draft plans to build the public pricing, the owner panel and the provider adapter against (ADR 0029). They are
 * placeholders: nothing is published and no price exists until the owner sets contents and prices.
 */
class SeedPlaceholderPlans extends Command
{
    protected $signature = 'billing:seed-placeholder-plans';

    protected $description = 'Create draft placeholder plans (Free / Start / Pro) if they do not exist';

    public const PLANS = [
        'free' => ['name' => 'Free', 'features' => [FeatureCatalog::PAYMENT_ATTEMPTS_SERVER], 'limits' => [FeatureCatalog::MAX_ACTIVE_STORES => 1, FeatureCatalog::MAX_EXTRA_STORES => 0, FeatureCatalog::MIN_CHECK_INTERVAL_MINUTES => 60, FeatureCatalog::RETENTION_DAYS => 30, FeatureCatalog::MANUAL_RUNS_PER_DAY => 1]],
        'start' => ['name' => 'Start', 'features' => [FeatureCatalog::PAYMENT_ATTEMPTS_SERVER, FeatureCatalog::CHECKOUT_MONITORING, FeatureCatalog::MONEY_RECONCILIATION], 'limits' => [FeatureCatalog::MAX_ACTIVE_STORES => 1, FeatureCatalog::MAX_EXTRA_STORES => 4, FeatureCatalog::MIN_CHECK_INTERVAL_MINUTES => 15, FeatureCatalog::RETENTION_DAYS => 180, FeatureCatalog::MANUAL_RUNS_PER_DAY => 10]],
        'pro' => ['name' => 'Pro', 'features' => FeatureCatalog::FEATURES, 'limits' => [FeatureCatalog::MAX_ACTIVE_STORES => 3, FeatureCatalog::MAX_EXTRA_STORES => 47, FeatureCatalog::MIN_CHECK_INTERVAL_MINUTES => 5, FeatureCatalog::RETENTION_DAYS => 365, FeatureCatalog::MANUAL_RUNS_PER_DAY => 50]],
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

        return self::SUCCESS;
    }
}
