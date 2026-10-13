<?php

namespace App\Support\Platform;

use App\Models\BillingPlanVersion;
use App\Models\BillingSubscriptionContract;
use App\Models\EntitlementOverride;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Support\Billing\BillingCheckoutRejected;
use App\Support\Billing\EntitlementCalculator;
use App\Support\Billing\OrderVolume;
use App\Support\Billing\FeatureCatalog;
use App\Support\Billing\Paddle\PaddleConfig;
use App\Support\Billing\Paddle\PaddleTransactions;
use App\Support\Billing\Paddle\PaddleRequestFailed;
use App\Support\Billing\Paddle\PaddleSubscriptionActions;
use App\Support\Billing\Paddle\PaddleSubscriptionSync;
use App\Support\Billing\StoreCapacity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Staff actions on an organisation (spec/panels/OWNER-PANEL.md §4, ADR 0038). Each one is previewed first — access
 * before and after, stores affected, dates — then applied with a reason and an authenticator code and audited.
 * Suspension never touches billing, resume never marks anything paid: access is recalculated by the usual policy.
 * Paid state is never edited; cancellation goes through the billing adapter like the owner's own.
 */
class TenantActions
{
    public const SUSPEND = 'suspend';

    public const RESUME = 'resume';

    public const GRANT_OVERRIDE = 'grant_override';

    public const REVOKE_OVERRIDE = 'revoke_override';

    public const EXTEND_TRIAL = 'extend_trial';

    public const CANCEL_SUBSCRIPTION = 'cancel_subscription';

    public const REFRESH_BILLING = 'refresh_billing';

    public const ACTIONS = [self::SUSPEND, self::RESUME, self::GRANT_OVERRIDE, self::REVOKE_OVERRIDE, self::EXTEND_TRIAL, self::CANCEL_SUBSCRIPTION, self::REFRESH_BILLING];

    public const MAX_OVERRIDE_DAYS = 180;

    public const MAX_TRIAL_EXTENSION_DAYS = 90;

    public function __construct(
        private readonly EntitlementCalculator $calculator,
        private readonly StoreCapacity $capacity,
        private readonly PaddleSubscriptionActions $subscriptions,
        private readonly PaddleSubscriptionSync $sync,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function preview(Tenant $tenant, string $action, array $input): array
    {
        $before = $this->accessOf($tenant->id);
        $stores = Store::query()->where('tenant_id', $tenant->id)->whereNotIn('status', ['deleted', 'deleting'])->count();

        if (in_array($action, [self::CANCEL_SUBSCRIPTION, self::REFRESH_BILLING], true)) {
            $contract = $this->subscriptions->liveContract($tenant->id);
            $this->check($action, $tenant, $input);

            return [
                'action' => $action,
                'access_before' => $before,
                'access_after' => $before,
                'stores' => $stores,
                'effective_at' => $action === self::CANCEL_SUBSCRIPTION ? $contract?->period_end?->toJSON() : null,
                'contracts' => BillingSubscriptionContract::query()->where('tenant_id', $tenant->id)->where('mode', PaddleConfig::mode())->whereNotNull('provider_subscription_ref')->count(),
            ];
        }

        $effective = null;
        $after = null;
        DB::beginTransaction();

        try {
            $effective = $this->applyLocal($tenant, $action, $input, null, '');
            $after = $this->accessOf($tenant->id);
        } finally {
            DB::rollBack();
            $this->calculator->forget();
        }

        return [
            'action' => $action,
            'access_before' => $before,
            'access_after' => $after,
            'stores' => $stores,
            'effective_at' => $effective,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function apply(Tenant $tenant, string $action, array $input, string $staffId, string $reason): void
    {
        match ($action) {
            self::CANCEL_SUBSCRIPTION => $this->cancel($tenant, $input, $reason),
            self::REFRESH_BILLING => $this->refresh($tenant),
            default => DB::transaction(fn () => $this->applyLocal(Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail(), $action, $input, $staffId, $reason)),
        };

        $this->calculator->forget();

        if (in_array($action, [self::GRANT_OVERRIDE, self::REVOKE_OVERRIDE, self::RESUME], true)) {
            $this->capacity->settle($tenant->id);
        }

        if (in_array($action, [self::GRANT_OVERRIDE, self::REVOKE_OVERRIDE], true) && Tenant::query()->whereKey($tenant->id)->whereNotNull('order_volume_paused_at')->exists()) {
            app(OrderVolume::class)->refreshTenant($tenant->id);
        }
    }

    /**
     * Applies a database-only action and returns when it takes effect.
     *
     * @param  array<string, mixed>  $input
     */
    private function applyLocal(Tenant $tenant, string $action, array $input, ?string $staffId, string $reason): ?string
    {
        $this->check($action, $tenant, $input);
        $now = Carbon::now();

        switch ($action) {
            case self::SUSPEND:
            case self::RESUME:
                $tenant->forceFill(['status' => $action === self::SUSPEND ? 'suspended' : 'active'])->save();
                $this->calculator->forget();

                return $now->toJSON();

            case self::GRANT_OVERRIDE:
                /** @var BillingPlanVersion $version */
                $version = BillingPlanVersion::query()->findOrFail($input['plan_version_id']);
                EntitlementOverride::query()->create([
                    'tenant_id' => $tenant->id,
                    'entitlements' => EntitlementCalculator::withStores(FeatureCatalog::normalize($version->entitlements), (int) ($input['extra_stores'] ?? 0)),
                    'reason' => $reason === '' ? 'preview' : $reason,
                    'created_by' => $staffId,
                    'approved_by' => $staffId,
                    'starts_at' => $now,
                    'ends_at' => Carbon::parse($input['ends_at']),
                    'created_at' => $now,
                ]);
                $this->calculator->forget();

                return Carbon::parse($input['ends_at'])->toJSON();

            case self::REVOKE_OVERRIDE:
                EntitlementOverride::query()->where('tenant_id', $tenant->id)->whereKey($input['override_id'])->update(['revoked_at' => $now]);
                $this->calculator->forget();

                return $now->toJSON();

            case self::EXTEND_TRIAL:
                /** @var Subscription $subscription */
                $subscription = Subscription::query()->where('tenant_id', $tenant->id)->lockForUpdate()->firstOrFail();
                $subscription->forceFill(['trial_ends_at' => Carbon::parse($input['trial_ends_at']), 'version' => $subscription->version + 1])->save();
                $this->calculator->forget();

                return Carbon::parse($input['trial_ends_at'])->toJSON();
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function check(string $action, Tenant $tenant, array $input): void
    {
        $now = Carbon::now();
        $reject = fn (string $code): never => throw new BillingCheckoutRejected($code);

        match ($action) {
            self::SUSPEND => $tenant->status === 'active' ?: $reject('tenant_not_active'),
            self::RESUME => $tenant->status === 'suspended' ?: $reject('tenant_not_suspended'),
            self::GRANT_OVERRIDE => (function () use ($tenant, $input, $now, $reject): void {
                $endsAt = isset($input['ends_at']) ? $this->date($input['ends_at']) : null;

                if (! isset($input['plan_version_id']) || ! Str::isUuid((string) $input['plan_version_id']) || ! BillingPlanVersion::query()->whereKey($input['plan_version_id'])->exists()) {
                    $reject('plan_not_available');
                }

                if ($endsAt === null || $endsAt->lessThanOrEqualTo($now) || $endsAt->greaterThan($now->copy()->addDays(self::MAX_OVERRIDE_DAYS))) {
                    $reject('override_period_invalid');
                }

                if (EntitlementOverride::query()->where('tenant_id', $tenant->id)->whereNull('revoked_at')->where('ends_at', '>', $now)->exists()) {
                    $reject('override_active');
                }
            })(),
            self::REVOKE_OVERRIDE => isset($input['override_id']) && Str::isUuid((string) $input['override_id'])
                && EntitlementOverride::query()->where('tenant_id', $tenant->id)->whereKey($input['override_id'])->whereNull('revoked_at')->where('ends_at', '>', $now)->exists()
                ?: $reject('override_not_found'),
            self::EXTEND_TRIAL => (function () use ($tenant, $input, $now, $reject): void {
                /** @var Subscription|null $subscription */
                $subscription = Subscription::query()->where('tenant_id', $tenant->id)->first();

                if ($subscription === null || $subscription->provider !== 'internal' || $subscription->status !== Subscription::STATUS_TRIAL) {
                    $reject('trial_not_running');
                }

                $until = isset($input['trial_ends_at']) ? $this->date($input['trial_ends_at']) : null;
                $floor = $subscription->trial_ends_at !== null && $subscription->trial_ends_at->greaterThan($now) ? $subscription->trial_ends_at : $now;

                if ($until === null || $until->lessThanOrEqualTo($floor) || $until->greaterThan($now->copy()->addDays(self::MAX_TRIAL_EXTENSION_DAYS))) {
                    $reject('trial_date_invalid');
                }
            })(),
            self::CANCEL_SUBSCRIPTION => (function () use ($tenant, $reject): void {
                $contract = $this->subscriptions->liveContract($tenant->id);

                if ($contract === null || $contract->normalized_state !== BillingSubscriptionContract::STATE_ACTIVE) {
                    $reject('no_active_subscription');
                }

                if ($contract->cancel_at_period_end) {
                    $reject('cancel_already_scheduled');
                }
            })(),
            self::REFRESH_BILLING => PaddleConfig::configured() ?: $reject('billing_not_configured'),
            default => $reject('action_unknown'),
        };
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function cancel(Tenant $tenant, array $input, string $reason): void
    {
        $this->check(self::CANCEL_SUBSCRIPTION, $tenant, $input);
        $this->subscriptions->cancel($tenant->id, null, $reason);
    }

    private function refresh(Tenant $tenant): void
    {
        $this->check(self::REFRESH_BILLING, $tenant, []);
        $refs = BillingSubscriptionContract::query()->where('tenant_id', $tenant->id)->where('mode', PaddleConfig::mode())->whereNotNull('provider_subscription_ref')->pluck('provider_subscription_ref');

        foreach ($refs as $ref) {
            try {
                $this->sync->sync((string) $ref);
                app(PaddleTransactions::class)->syncSubscription((string) $ref);
            } catch (PaddleRequestFailed $exception) {
                throw new BillingCheckoutRejected($exception->outcomeUnknown() ? 'provider_unavailable' : 'plan_change_rejected');
            }
        }
    }

    /**
     * @return array{state: string, reasons: list<string>}
     */
    private function accessOf(string $tenantId): array
    {
        $this->calculator->forget();
        $entitlements = $this->calculator->forTenant($tenantId);

        return ['state' => $entitlements->access, 'reasons' => $entitlements->reasons];
    }

    private function date(mixed $value): ?Carbon
    {
        try {
            return is_string($value) ? Carbon::parse($value) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
