<?php

namespace App\Support\Billing;

use App\Models\BillingPlan;
use App\Models\BillingPlanVersion;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Support\Billing\Paddle\PaddleConfig;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Our own trial without a payment method (owner, ADR 0030): once per tenant, consumed under the tenant lock, for the
 * trial days of the chosen plan version. A purchase later replaces the trial row; when the trial ends unpaid the
 * entitlement calculator pauses the service.
 */
class TrialService
{
    public function start(string $tenantId, string $planVersionId): Subscription
    {
        return DB::transaction(function () use ($tenantId, $planVersionId): Subscription {
            /** @var Tenant $tenant */
            $tenant = Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();

            if ($tenant->trial_consumed_at !== null || Subscription::query()->where('tenant_id', $tenantId)->exists()) {
                throw new BillingCheckoutRejected('trial_not_available');
            }

            $states = PaddleConfig::sandbox() ? [BillingPlan::STATE_PUBLISHED, BillingPlan::STATE_DRAFT] : [BillingPlan::STATE_PUBLISHED];
            /** @var BillingPlanVersion|null $version */
            $version = BillingPlanVersion::query()
                ->with('plan')
                ->whereKey($planVersionId)
                ->whereIn('state', $states)
                ->whereHas('plan', fn ($query) => $query->whereIn('state', $states))
                ->where('trial_days', '>', 0)
                ->first();

            if ($version === null) {
                throw new BillingCheckoutRejected('plan_not_available');
            }

            $now = Carbon::now();
            $tenant->forceFill(['trial_consumed_at' => $now])->save();

            return Subscription::query()->create([
                'tenant_id' => $tenantId,
                'provider' => 'internal',
                'plan_code' => $version->plan->code,
                'plan_version_id' => $version->id,
                'entitlements' => FeatureCatalog::normalize($version->entitlements),
                'extra_store_quantity' => 0,
                'status' => Subscription::STATUS_TRIAL,
                'period_start' => $now,
                'trial_ends_at' => $now->copy()->addDays($version->trial_days),
                'version' => 1,
            ]);
        });
    }
}
