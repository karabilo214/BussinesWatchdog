<?php

namespace App\Support\Billing;

use App\Models\BillingCustomer;
use App\Models\BillingPlanChange;
use App\Models\BillingPlanVersion;
use App\Models\BillingPrice;
use App\Models\BillingSubscriptionContract;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Provider-neutral: turns the tenant's provider contracts of one mode into the single `subscriptions` row the
 * entitlement calculator reads. A live contract (active, trial, past due) wins over an ended one; among equals the one
 * with the latest period end. A contract in an unknown state never changes the row (no grant, no cut). A cheaper plan
 * scheduled for the next period is already on the provider subscription; the paid plan stays until the change is due.
 */
class SubscriptionProjector
{
    private const STATUS = [
        BillingSubscriptionContract::STATE_ACTIVE => Subscription::STATUS_ACTIVE,
        BillingSubscriptionContract::STATE_TRIAL => Subscription::STATUS_TRIAL,
        BillingSubscriptionContract::STATE_PAST_DUE => Subscription::STATUS_PAST_DUE,
        BillingSubscriptionContract::STATE_UNPAID => Subscription::STATUS_SUSPENDED,
        BillingSubscriptionContract::STATE_CANCELLED => Subscription::STATUS_CANCELLED,
    ];

    private const LIVE = [BillingSubscriptionContract::STATE_ACTIVE, BillingSubscriptionContract::STATE_TRIAL, BillingSubscriptionContract::STATE_PAST_DUE];

    public function project(string $tenantId, string $mode, ?Carbon $now = null): ?Subscription
    {
        $now ??= Carbon::now();
        $contract = BillingSubscriptionContract::query()
            ->where('tenant_id', $tenantId)
            ->where('mode', $mode)
            ->whereIn('normalized_state', array_keys(self::STATUS))
            ->whereNotNull('plan_version_id')
            ->get()
            ->sortByDesc(fn (BillingSubscriptionContract $contract): string => (in_array($contract->normalized_state, self::LIVE, true) ? '1' : '0').($contract->period_end?->format('YmdHis') ?? '00000000000000'))
            ->first();

        if ($contract === null) {
            return null;
        }

        /** @var BillingPlanChange|null $scheduled */
        $scheduled = BillingPlanChange::query()
            ->where('contract_id', $contract->id)
            ->where('status', BillingPlanChange::STATUS_SCHEDULED)
            ->where('effective_at', '>', $now)
            ->first();
        $versionId = $scheduled === null ? $contract->plan_version_id : BillingPrice::query()->whereKey($scheduled->from_price_id)->value('plan_version_id');
        $extraStores = $scheduled === null ? $contract->extra_store_quantity : $scheduled->from_extra_store_quantity;
        $faster = $scheduled === null ? $contract->faster_checks : $scheduled->from_faster_checks;
        /** @var BillingPlanVersion $version */
        $version = BillingPlanVersion::query()->with('plan')->findOrFail($versionId);
        /** @var Subscription|null $row */
        $row = Subscription::query()->where('tenant_id', $tenantId)->lockForUpdate()->first();
        $status = self::STATUS[$contract->normalized_state];
        $attributes = [
            'provider' => $contract->provider,
            'provider_customer_ref' => $contract->customer_id === null ? null : BillingCustomer::query()->whereKey($contract->customer_id)->value('provider_customer_ref'),
            'provider_subscription_ref' => $contract->provider_subscription_ref,
            'plan_code' => $version->plan->code,
            'plan_version_id' => $version->id,
            'entitlements' => FeatureCatalog::normalize($version->entitlements),
            'extra_store_quantity' => $extraStores,
            'faster_check_interval_minutes' => $faster ? $version->faster_check_interval_minutes : null,
            'status' => $status,
            'period_start' => $contract->period_start,
            'period_end' => $contract->paid_until ?? $contract->period_end,
            'trial_ends_at' => $status === Subscription::STATUS_TRIAL ? $contract->period_end : null,
            'past_due_since' => $status === Subscription::STATUS_PAST_DUE ? ($row?->status === Subscription::STATUS_PAST_DUE ? $row->past_due_since : $now) : null,
            'cancel_at_period_end' => $contract->cancel_at_period_end,
        ];

        if ($row === null) {
            $row = Subscription::query()->create(['tenant_id' => $tenantId, ...$attributes, 'version' => 1]);
            $this->resumeIfCovered($tenantId);

            return $row;
        }

        $row->fill($attributes);

        if ($row->isDirty()) {
            $row->version++;
            $row->save();
            $this->resumeIfCovered($tenantId);
        }

        return $row;
    }

    /**
     * An organisation paused for its order volume resumes as soon as the new package covers it (ADR 0041).
     */
    private function resumeIfCovered(string $tenantId): void
    {
        if (Tenant::query()->whereKey($tenantId)->whereNotNull('order_volume_paused_at')->exists()) {
            DB::afterCommit(fn () => app(OrderVolume::class)->refreshTenant($tenantId));
        }
    }
}
