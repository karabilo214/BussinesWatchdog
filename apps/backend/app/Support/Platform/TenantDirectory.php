<?php

namespace App\Support\Platform;

use App\Models\BillingPlanChange;
use App\Models\BillingSubscriptionContract;
use App\Models\EntitlementOverride;
use App\Models\Incident;
use App\Models\Membership;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Billing\EntitlementCalculator;
use App\Support\Billing\FeatureCatalog;
use App\Support\Billing\Paddle\PaddleConfig;
use App\Support\Billing\Paddle\PaddleSubscriptionActions;
use App\Support\Billing\StoreCapacity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Organisations as the service's own staff see them (spec/panels/OWNER-PANEL.md §4): commercial and access state side by
 * side, stores only as a summary — no findings, orders or screenshots without a support grant, no provider secrets.
 * Commercial fields need `billing.read`; full contact emails are shown to the platform owner only.
 */
class TenantDirectory
{
    public const PAGE = 50;

    public const SUBSCRIPTION_FILTERS = ['none', Subscription::STATUS_TRIAL, Subscription::STATUS_ACTIVE, Subscription::STATUS_PAST_DUE, Subscription::STATUS_SUSPENDED, Subscription::STATUS_CANCELLED];

    public function __construct(
        private readonly EntitlementCalculator $calculator,
        private readonly StoreCapacity $capacity,
    ) {}

    /**
     * @param  array{q?: string, tenant_status?: string, subscription?: string, plan?: string, cancel_scheduled?: bool, trial_ends_before?: string, cursor?: string}  $filters
     * @return array{data: list<array<string, mixed>>, next_cursor: ?string}
     */
    public function tenants(array $filters, bool $billing, bool $fullContacts): array
    {
        $query = Tenant::query()
            ->whereNotIn('tenants.status', ['deleted'])
            ->leftJoin('subscriptions', 'subscriptions.tenant_id', '=', 'tenants.id')
            ->select('tenants.*');

        if (($q = trim($filters['q'] ?? '')) !== '') {
            $query->where(fn (Builder $match) => $match
                ->whereRaw('lower(tenants.name) like ?', ['%'.mb_strtolower($q).'%'])
                ->when(Str::isUuid($q), fn (Builder $id) => $id->orWhere('tenants.id', $q))
                ->when(str_contains($q, '@'), fn (Builder $email) => $email->orWhereIn('tenants.id', Membership::query()
                    ->where('role', 'owner')
                    ->whereIn('user_id', User::query()->whereRaw('lower(email) = ?', [mb_strtolower($q)])->whereNotNull('email_verified_at')->select('id'))
                    ->select('tenant_id'))));
        }

        if (isset($filters['tenant_status'])) {
            $query->where('tenants.status', $filters['tenant_status']);
        }

        if ($billing) {
            match ($filters['subscription'] ?? null) {
                null => null,
                'none' => $query->whereNull('subscriptions.id'),
                default => $query->where('subscriptions.status', $filters['subscription']),
            };

            if (isset($filters['plan'])) {
                $query->where('subscriptions.plan_code', $filters['plan']);
            }

            if (! empty($filters['cancel_scheduled'])) {
                $query->where('subscriptions.cancel_at_period_end', true);
            }

            if (isset($filters['trial_ends_before'])) {
                $query->where('subscriptions.status', Subscription::STATUS_TRIAL)->where('subscriptions.trial_ends_at', '<', Carbon::parse($filters['trial_ends_before']));
            }
        }

        if (isset($filters['cursor'])) {
            $query->where('tenants.id', '<', $filters['cursor']);
        }

        $rows = $query->orderByDesc('tenants.id')->limit(self::PAGE + 1)->get();
        $page = $rows->take(self::PAGE);
        $ids = $page->modelKeys();
        $owners = $this->owners($ids);
        $subscriptions = Subscription::query()->whereIn('tenant_id', $ids)->get()->keyBy('tenant_id');
        $incidents = Incident::query()->whereIn('tenant_id', $ids)->whereIn('state', Incident::ACTIVE_STATES)->groupBy('tenant_id')->selectRaw('tenant_id, count(*) as total')->pluck('total', 'tenant_id');
        $overrides = $this->activeOverrides($ids);

        return [
            'data' => $page->map(fn (Tenant $tenant): array => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'status' => $tenant->status,
                'created_at' => $tenant->created_at?->toJSON(),
                'owner' => $this->contact($owners->get($tenant->id), $fullContacts),
                'access' => $this->access($tenant->id),
                'subscription' => $billing ? $this->subscriptionSummary($subscriptions->get($tenant->id)) : null,
                'stores' => $this->capacity->state($tenant->id),
                'open_incidents' => (int) ($incidents[$tenant->id] ?? 0),
                'override_active' => $overrides->contains($tenant->id),
            ])->values()->all(),
            'next_cursor' => $rows->count() > self::PAGE ? (string) $page->last()->id : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function tenant(string $tenantId, bool $billing, bool $fullContacts): ?array
    {
        /** @var Tenant|null $tenant */
        $tenant = Str::isUuid($tenantId) ? Tenant::query()->whereKey($tenantId)->whereNot('status', 'deleted')->first() : null;

        if ($tenant === null) {
            return null;
        }

        $this->calculator->forget();
        $members = Membership::query()->where('tenant_id', $tenant->id)->with('user')->orderBy('created_at')->get();
        $incidents = Incident::query()->where('tenant_id', $tenant->id)->whereIn('state', Incident::ACTIVE_STATES)->groupBy('store_id')->selectRaw('store_id, count(*) as total')->pluck('total', 'store_id');

        return [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'status' => $tenant->status,
            'locale' => $tenant->locale,
            'timezone' => $tenant->timezone,
            'created_at' => $tenant->created_at?->toJSON(),
            'trial_consumed_at' => $tenant->trial_consumed_at?->toJSON(),
            'access' => $this->access($tenant->id),
            'capacity' => $this->capacity->state($tenant->id),
            'members' => $members->map(fn (Membership $member): array => [
                'role' => $member->role,
                'joined_at' => $member->created_at?->toJSON(),
            ] + $this->contact($member->user, $fullContacts))->values()->all(),
            'stores' => Store::query()->where('tenant_id', $tenant->id)->whereNot('status', 'deleted')->orderBy('created_at')->get()->map(fn (Store $store): array => [
                'id' => $store->id,
                'name' => $store->name,
                'host' => parse_url($store->base_url, PHP_URL_HOST) ?: $store->base_url,
                'platform' => $store->platform,
                'status' => $store->status,
                'verified' => $store->verified_at !== null,
                'browser_enabled' => $store->browser_enabled,
                'capacity_paused_at' => $store->capacity_paused_at?->toJSON(),
                'open_incidents' => (int) ($incidents[$store->id] ?? 0),
                'orders_this_month' => (int) DB::table('store_order_volumes')->where('store_id', $store->id)->where('month', Carbon::now()->utc()->startOfMonth()->toDateString())->value('orders'),
                'orders_average' => $store->order_volume_average,
                'orders_over_since' => $store->order_volume_over_since?->toJSON(),
                'created_at' => $store->created_at?->toJSON(),
            ])->values()->all(),
            'orders_limit' => $this->calculator->forTenant($tenant->id)->limit(FeatureCatalog::MAX_ORDERS_PER_MONTH),
            'billing' => $billing ? $this->billing($tenant) : null,
            'audit' => $this->audit($tenant->id),
        ];
    }

    /**
     * @param  array{status?: string, plan?: string, cancel_scheduled?: bool, cursor?: string}  $filters
     * @return array{data: list<array<string, mixed>>, next_cursor: ?string}
     */
    public function subscriptions(array $filters): array
    {
        $query = Subscription::query()
            ->join('tenants', 'tenants.id', '=', 'subscriptions.tenant_id')
            ->whereNot('tenants.status', 'deleted')
            ->select('subscriptions.*', 'tenants.name as tenant_name', 'tenants.status as tenant_status')
            ->when(isset($filters['status']), fn (Builder $query) => $query->where('subscriptions.status', $filters['status']))
            ->when(isset($filters['plan']), fn (Builder $query) => $query->where('subscriptions.plan_code', $filters['plan']))
            ->when(! empty($filters['cancel_scheduled']), fn (Builder $query) => $query->where('subscriptions.cancel_at_period_end', true))
            ->when(isset($filters['cursor']), fn (Builder $query) => $query->where('subscriptions.id', '<', $filters['cursor']));
        $rows = $query->orderByDesc('subscriptions.id')->limit(self::PAGE + 1)->get();
        $page = $rows->take(self::PAGE);
        $contracts = $this->liveContracts($page->pluck('tenant_id')->all());

        return [
            'data' => $page->map(fn (Subscription $subscription): array => [
                'tenant' => ['id' => $subscription->tenant_id, 'name' => $subscription->getAttribute('tenant_name'), 'status' => $subscription->getAttribute('tenant_status')],
                'access' => $this->access($subscription->tenant_id),
                'next_charge_at' => $contracts->get($subscription->tenant_id)?->next_charge_at?->toJSON(),
                'provider_subscription_ref' => $subscription->provider_subscription_ref,
                'mode' => $contracts->get($subscription->tenant_id)?->mode,
            ] + $this->subscriptionSummary($subscription))->values()->all(),
            'next_cursor' => $rows->count() > self::PAGE ? (string) $page->last()->id : null,
        ];
    }

    /**
     * @return array{state: string, reasons: list<string>, enforced: bool}
     */
    public function access(string $tenantId): array
    {
        $this->calculator->forget();
        $entitlements = $this->calculator->forTenant($tenantId);

        return ['state' => $entitlements->access, 'reasons' => $entitlements->reasons, 'enforced' => $entitlements->enforced];
    }

    public static function maskEmail(string $email): string
    {
        $at = strrpos($email, '@');

        if ($at === false) {
            return '***';
        }

        return mb_substr($email, 0, 1).'***'.substr($email, $at);
    }

    /**
     * @return array<string, mixed>
     */
    private function billing(Tenant $tenant): array
    {
        /** @var Subscription|null $subscription */
        $subscription = Subscription::query()->where('tenant_id', $tenant->id)->first();
        $actions = app(PaddleSubscriptionActions::class);
        $live = $actions->liveContract($tenant->id);
        $scheduled = $live === null ? null : $actions->scheduledChange($live->id);

        return [
            'mode' => PaddleConfig::mode(),
            'subscription' => $subscription === null ? null : $this->subscriptionSummary($subscription) + [
                'provider_subscription_ref' => $subscription->provider_subscription_ref,
                'next_charge_at' => $live?->next_charge_at?->toJSON(),
                'scheduled_change' => $scheduled === null ? null : PaddleSubscriptionActions::dto($scheduled),
            ],
            'contracts' => BillingSubscriptionContract::query()->where('tenant_id', $tenant->id)->orderByDesc('created_at')->limit(20)->get()->map(fn (BillingSubscriptionContract $contract): array => [
                'id' => $contract->id,
                'provider' => $contract->provider,
                'provider_subscription_ref' => $contract->provider_subscription_ref,
                'mode' => $contract->mode,
                'state' => $contract->normalized_state,
                'provider_state' => $contract->provider_state,
                'plan_code' => $contract->plan_version_id === null ? null : DB::table('billing_plan_versions')->join('billing_plans', 'billing_plans.id', '=', 'billing_plan_versions.plan_id')->where('billing_plan_versions.id', $contract->plan_version_id)->value('billing_plans.code'),
                'extra_store_quantity' => (int) $contract->extra_store_quantity,
                'currency' => $contract->currency,
                'period_start' => $contract->period_start?->toJSON(),
                'period_end' => $contract->period_end?->toJSON(),
                'paid_until' => $contract->paid_until?->toJSON(),
                'next_charge_at' => $contract->next_charge_at?->toJSON(),
                'cancel_at_period_end' => $contract->cancel_at_period_end,
                'problem_code' => $contract->problem_code,
                'synced_at' => $contract->updated_at?->toJSON(),
            ])->values()->all(),
            'plan_changes' => BillingPlanChange::query()->where('tenant_id', $tenant->id)->whereNot('status', BillingPlanChange::STATUS_PREVIEWED)->whereNot('status', BillingPlanChange::STATUS_EXPIRED)->orderByDesc('created_at')->limit(20)->get()
                ->map(fn (BillingPlanChange $change): array => PaddleSubscriptionActions::dto($change) + ['created_at' => $change->created_at?->toJSON()])->values()->all(),
            'overrides' => EntitlementOverride::query()->where('tenant_id', $tenant->id)->orderByDesc('created_at')->limit(20)->get()->map(fn (EntitlementOverride $override): array => [
                'id' => $override->id,
                'entitlements' => $override->entitlements,
                'reason' => $override->reason,
                'starts_at' => $override->starts_at->toJSON(),
                'ends_at' => $override->ends_at->toJSON(),
                'revoked_at' => $override->revoked_at?->toJSON(),
                'active' => $override->revoked_at === null && $override->starts_at->lessThanOrEqualTo(Carbon::now()) && $override->ends_at->greaterThan(Carbon::now()),
                'created_at' => $override->created_at?->toJSON(),
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function subscriptionSummary(?Subscription $subscription): ?array
    {
        if ($subscription === null) {
            return null;
        }

        return [
            'id' => $subscription->id,
            'provider' => $subscription->provider,
            'plan_code' => $subscription->plan_code,
            'plan_version_id' => $subscription->plan_version_id,
            'status' => $subscription->status,
            'paid_until' => $subscription->status === Subscription::STATUS_ACTIVE ? $subscription->period_end?->toJSON() : null,
            'trial_ends_at' => $subscription->trial_ends_at?->toJSON(),
            'period_end' => $subscription->period_end?->toJSON(),
            'past_due_since' => $subscription->past_due_since?->toJSON(),
            'cancel_at_period_end' => $subscription->cancel_at_period_end,
            'extra_store_quantity' => $subscription->extra_store_quantity,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function audit(string $tenantId): array
    {
        return DB::table('platform_audit_log')
            ->leftJoin('platform_staff', 'platform_staff.id', '=', 'platform_audit_log.actor_staff_id')
            ->leftJoin('users', 'users.id', '=', 'platform_staff.user_id')
            ->where('platform_audit_log.target_tenant_id', $tenantId)
            ->orderByDesc('platform_audit_log.created_at')
            ->limit(50)
            ->get(['platform_audit_log.*', 'users.name as actor_name'])
            ->map(fn (object $row): array => [
                'id' => $row->id,
                'action' => $row->action,
                'actor' => $row->actor_name,
                'reason' => $row->reason,
                'result' => $row->result,
                'changes' => json_decode((string) $row->sanitized_changes, true),
                'created_at' => Carbon::parse($row->created_at)->toJSON(),
            ])->values()->all();
    }

    /**
     * @param  list<string>  $tenantIds
     * @return Collection<string, User>
     */
    private function owners(array $tenantIds): Collection
    {
        return Membership::query()->whereIn('tenant_id', $tenantIds)->where('role', 'owner')->with('user')->get()
            ->mapWithKeys(fn (Membership $membership): array => [$membership->tenant_id => $membership->user]);
    }

    /**
     * @param  list<string>  $tenantIds
     * @return Collection<int, string>
     */
    private function activeOverrides(array $tenantIds): Collection
    {
        $now = Carbon::now();

        return EntitlementOverride::query()->whereIn('tenant_id', $tenantIds)->whereNull('revoked_at')->where('starts_at', '<=', $now)->where('ends_at', '>', $now)->pluck('tenant_id');
    }

    /**
     * @param  list<string>  $tenantIds
     * @return Collection<string, BillingSubscriptionContract>
     */
    private function liveContracts(array $tenantIds): Collection
    {
        return BillingSubscriptionContract::query()
            ->whereIn('tenant_id', $tenantIds)
            ->where('mode', PaddleConfig::mode())
            ->whereIn('normalized_state', [BillingSubscriptionContract::STATE_ACTIVE, BillingSubscriptionContract::STATE_PAST_DUE, BillingSubscriptionContract::STATE_TRIAL])
            ->orderBy('period_end')
            ->get()
            ->keyBy('tenant_id');
    }

    /**
     * @return array{name: ?string, email: ?string, email_verified: bool}
     */
    private function contact(?User $user, bool $full): array
    {
        if ($user === null) {
            return ['name' => null, 'email' => null, 'email_verified' => false];
        }

        return [
            'name' => $user->name,
            'email' => $full ? $user->email : self::maskEmail($user->email),
            'email_verified' => $user->email_verified_at !== null,
        ];
    }
}
