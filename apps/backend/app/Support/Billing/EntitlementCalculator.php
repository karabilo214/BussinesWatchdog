<?php

namespace App\Support\Billing;

use App\Models\EntitlementOverride;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Support\Carbon;

/**
 * Decides what a tenant may use (spec/panels/ADMIN-BACKEND.md §4, ADR 0029), in this order: deleting/deleted → no
 * service; suspended tenant → paused (manual, an override does not lift it); paid period → plan snapshot; active
 * override → explicit grant (on top of a paid period); trial until its end; past due within the grace → previous
 * snapshot; otherwise paused by billing (a subscription that lapsed) or onboarding (never subscribed). In pilot mode
 * the outcome is reported but not enforced.
 */
class EntitlementCalculator
{
    public const MODE_PILOT = 'pilot';

    public const MODE_ENFORCE = 'enforce';

    /** @var array<string, Entitlements> */
    private array $memo = [];

    public function forTenant(string $tenantId, ?Carbon $now = null): Entitlements
    {
        $now ??= Carbon::now();
        $key = $tenantId.'|'.$now->getTimestamp();

        return $this->memo[$key] ??= $this->calculate($tenantId, $now);
    }

    public function forget(): void
    {
        $this->memo = [];
    }

    public static function enforced(): bool
    {
        return config('watchdog.billing.enforcement') === self::MODE_ENFORCE;
    }

    /**
     * The more generous value of each limit of the paid plan and an override: null or missing in the plan means
     * "no limit"; a limit the override does not mention stays as the plan has it; the check interval is a minimum,
     * so the smaller one wins, every other limit takes the larger one.
     *
     * @param  array<string, ?int>  $plan
     * @param  array<string, ?int>  $grant
     * @return array<string, ?int>
     */
    public static function widerLimits(array $plan, array $grant): array
    {
        $limits = $plan;

        foreach ($grant as $key => $granted) {
            $current = $plan[$key] ?? null;
            $limits[$key] = $current === null || $granted === null ? null : ($key === FeatureCatalog::MIN_CHECK_INTERVAL_MINUTES ? min($current, $granted) : max($current, $granted));
        }

        ksort($limits);

        return $limits;
    }

    /**
     * Stores bought on top of the plan raise `max_active_stores` (capped by `max_extra_stores`); per-store limits are
     * multiplied by the resulting number of stores, because every store brings its own load.
     *
     * @param  array{features: list<string>, limits: array<string, ?int>}  $entitlements
     * @return array{features: list<string>, limits: array<string, ?int>}
     */
    public static function withStores(array $entitlements, int $extraStores): array
    {
        $limits = $entitlements['limits'];
        $included = $limits[FeatureCatalog::MAX_ACTIVE_STORES] ?? null;

        if ($included === null) {
            return $entitlements;
        }

        $cap = $limits[FeatureCatalog::MAX_EXTRA_STORES] ?? null;
        $stores = $included + max(0, $cap === null ? $extraStores : min($extraStores, $cap));
        $limits[FeatureCatalog::MAX_ACTIVE_STORES] = $stores;

        foreach (FeatureCatalog::PER_STORE_LIMITS as $limit) {
            if (($limits[$limit] ?? null) !== null) {
                $limits[$limit] *= max(1, $stores);
            }
        }

        return ['features' => $entitlements['features'], 'limits' => $limits];
    }

    private function calculate(string $tenantId, Carbon $now): Entitlements
    {
        $enforced = self::enforced();
        $status = Tenant::query()->whereKey($tenantId)->value('status');

        if ($status === null || in_array($status, ['deleting', 'deleted'], true)) {
            return new Entitlements(Entitlements::ACCESS_NO_SERVICE, ['tenant_'.($status ?? 'missing')], null, [], [], true);
        }

        if ($status === 'suspended') {
            return new Entitlements(Entitlements::ACCESS_PAUSED_MANUAL, ['tenant_suspended'], null, [], [], true);
        }

        if (! $enforced) {
            $everything = FeatureCatalog::everything();

            return new Entitlements(Entitlements::ACCESS_PILOT, ['pilot_manual_grant'], null, $everything['features'], $everything['limits'], false);
        }

        /** @var Subscription|null $subscription */
        $subscription = Subscription::query()->where('tenant_id', $tenantId)->first();
        /** @var EntitlementOverride|null $override */
        $override = EntitlementOverride::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('revoked_at')
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>', $now)
            ->latest('starts_at')
            ->first();
        $snapshot = $subscription === null ? null : self::withStores(FeatureCatalog::normalize($subscription->entitlements ?? []), (int) $subscription->extra_store_quantity);
        $paid = $subscription !== null && $subscription->status === Subscription::STATUS_ACTIVE && ($subscription->period_end === null || $subscription->period_end->greaterThan($now));

        if ($override !== null) {
            $grant = FeatureCatalog::normalize($override->entitlements ?? []);
            $base = $paid ? $snapshot : ['features' => [], 'limits' => []];
            $features = array_values(array_unique([...$base['features'], ...$grant['features']]));
            sort($features);
            $limits = $paid ? self::widerLimits($base['limits'], $grant['limits']) : $grant['limits'];

            return new Entitlements(Entitlements::ACCESS_OVERRIDE, array_values(array_filter(['override_active', $paid ? 'paid_period' : null])), $subscription?->plan_code, $features, $limits, true);
        }

        if ($paid) {
            return new Entitlements(Entitlements::ACCESS_ACTIVE, array_values(array_filter(['paid_period', $subscription->cancel_at_period_end ? 'cancel_at_period_end' : null])), $subscription->plan_code, $snapshot['features'], $snapshot['limits'], true);
        }

        if ($subscription?->status === Subscription::STATUS_TRIAL && $subscription->trial_ends_at !== null && $subscription->trial_ends_at->greaterThan($now)) {
            return new Entitlements(Entitlements::ACCESS_TRIAL, ['trial'], $subscription->plan_code, $snapshot['features'], $snapshot['limits'], true);
        }

        $graceDays = (int) config('watchdog.billing.past_due_grace_days');

        if ($subscription?->status === Subscription::STATUS_PAST_DUE && $subscription->past_due_since !== null && $subscription->past_due_since->copy()->addDays($graceDays)->greaterThan($now)) {
            return new Entitlements(Entitlements::ACCESS_GRACE, ['renewal_failed_grace'], $subscription->plan_code, $snapshot['features'], $snapshot['limits'], true);
        }

        if ($subscription === null) {
            return new Entitlements(Entitlements::ACCESS_ONBOARDING, ['no_subscription'], null, [], [], true);
        }

        return new Entitlements(Entitlements::ACCESS_PAUSED_BILLING, ['subscription_'.$subscription->status], $subscription->plan_code, [], $snapshot['limits'], true);
    }
}
