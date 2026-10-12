<?php

namespace App\Support\Billing;

use App\Models\BillingPlanVersion;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Our own trial without a payment method (owner, ADR 0030): once per tenant and once per store domain (ADR 0031),
 * consumed under the tenant lock, for the trial days of the chosen plan version. A purchase later replaces the trial row; when the trial ends unpaid the
 * entitlement calculator pauses the service.
 */
class TrialService
{
    public const PROVIDER = 'internal';

    public function __construct(
        private readonly TrialStoreHosts $hosts,
    ) {}

    public function start(string $tenantId, string $planVersionId): Subscription
    {
        return DB::transaction(function () use ($tenantId, $planVersionId): Subscription {
            /** @var Tenant $tenant */
            $tenant = Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();

            if ($tenant->trial_consumed_at !== null || Subscription::query()->where('tenant_id', $tenantId)->exists()) {
                throw new BillingCheckoutRejected('trial_not_available');
            }

            $states = BillingCatalog::sellableStates();
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

            $hosts = $this->hosts->verifiedStoreHosts($tenantId);

            if ($this->hosts->claimedByOther($tenantId, array_keys($hosts))) {
                throw new BillingCheckoutRejected('trial_store_already_used');
            }

            $now = Carbon::now();

            foreach ($hosts as $host => $storeId) {
                $this->hosts->claim($tenantId, $storeId, $host, $now);
            }

            $tenant->forceFill(['trial_consumed_at' => $now])->save();

            return Subscription::query()->create([
                'tenant_id' => $tenantId,
                'provider' => self::PROVIDER,
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
