<?php

namespace App\Support\Billing;

use App\Models\BillingPlan;
use App\Models\BillingPlanVersion;
use App\Models\Membership;
use App\Models\Order;
use App\Models\ReconciliationDirtySubject;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Account\AccountMailer;
use App\Support\Auth\TenantRoles;
use App\Support\Reconciliation\StoreReconciliationRequeue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Order volume of a store for volume-based packages (ADR 0041): every real order is counted once, in the month it was
 * placed, when the service first sees it — repeated syncs, status changes and refunds never count, and neither do
 * orders placed before the store was added (the history import). A store is over its package when the average of
 * the last three complete months exceeds `max_orders_per_month`; the owner is told once and gets the package's grace
 * period to move to a bigger package. The subscription belongs to the organisation, so when the grace period of any
 * of its stores ends the organisation is paused (owner's decision 2026-10-12; `EntitlementCalculator` then reports
 * `paused_billing` / `order_volume_exceeded` and every protection feature stops, while store data is still received)
 * until a package that covers the volume of every store is bought; then it resumes at once and its orders are
 * re-checked. Nothing is charged automatically.
 */
class OrderVolume
{
    public const AVERAGE_MONTHS = 3;

    public const REASON_PAUSED = 'order_volume_paused';

    public function __construct(
        private readonly EntitlementCalculator $entitlements,
        private readonly AccountMailer $mailer,
        private readonly StoreReconciliationRequeue $requeue,
    ) {}

    public function count(Order $order): void
    {
        if ($order->is_synthetic || $order->source_created_at === null) {
            return;
        }

        $since = Store::query()->whereKey($order->store_id)->value('created_at');

        if ($since === null || $order->source_created_at->lessThan(Carbon::parse($since))) {
            return;
        }

        $month = $order->source_created_at->copy()->utc()->startOfMonth()->toDateString();
        DB::table('store_order_volumes')->insertOrIgnore(['tenant_id' => $order->tenant_id, 'store_id' => $order->store_id, 'month' => $month, 'orders' => 0, 'updated_at' => Carbon::now()]);
        DB::table('store_order_volumes')->where('store_id', $order->store_id)->where('month', $month)->update(['orders' => DB::raw('orders + 1'), 'updated_at' => Carbon::now()]);
    }

    /**
     * Months count only when the store existed for the whole month, so a partial first month does not lower the average.
     *
     * @return array{this_month: int, average: ?int, months: list<array{month: string, orders: int}>}
     */
    public function figures(Store $store, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $current = $now->copy()->utc()->startOfMonth();
        $first = $current->copy()->subMonths(self::AVERAGE_MONTHS);
        $counted = DB::table('store_order_volumes')
            ->where('store_id', $store->id)
            ->where('month', '>=', $first->toDateString())
            ->pluck('orders', 'month')
            ->mapWithKeys(fn ($orders, $month): array => [substr((string) $month, 0, 10) => (int) $orders]);
        $months = [];

        for ($month = $first->copy(); $month->lessThan($current); $month->addMonth()) {
            if ($store->created_at === null || $month->greaterThanOrEqualTo($store->created_at)) {
                $months[] = ['month' => $month->toDateString(), 'orders' => $counted[$month->toDateString()] ?? 0];
            }
        }

        return [
            'this_month' => $counted[$current->toDateString()] ?? 0,
            'average' => $months === [] ? null : (int) ceil(array_sum(array_column($months, 'orders')) / count($months)),
            'months' => $months,
        ];
    }

    /**
     * Daily: refreshes every store's average and its over-the-package state; notifies owners once per crossing, pauses
     * a store whose grace period has ended and resumes one the package covers again.
     *
     * @return array{stores: int, over: int, notified: int, paused: int, resumed: int}
     */
    public function evaluate(?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $result = ['stores' => 0, 'over' => 0, 'notified' => 0, 'paused' => 0, 'resumed' => 0];

        Tenant::query()->whereIn('status', ['active'])->orderBy('id')->select('id')->chunkById(200, function ($tenants) use ($now, &$result): void {
            foreach ($tenants as $tenant) {
                $this->evaluateTenant($tenant->id, $now, $result);
            }
        });

        return $result;
    }

    /**
     * Right after the package changes (purchase, upgrade, temporary access): an organisation whose stores the package
     * now covers resumes at once instead of waiting for the night.
     *
     * @return array{stores: int, over: int, notified: int, paused: int, resumed: int}
     */
    public function refreshTenant(string $tenantId, ?Carbon $now = null): array
    {
        $result = ['stores' => 0, 'over' => 0, 'notified' => 0, 'paused' => 0, 'resumed' => 0];
        $this->entitlements->forget();
        $this->evaluateTenant($tenantId, $now ?? Carbon::now(), $result);

        return $result;
    }

    /**
     * @param  array<string, int>  $result
     */
    private function evaluateTenant(string $tenantId, Carbon $now, array &$result): void
    {
        /** @var Tenant|null $tenant */
        $tenant = Tenant::query()->find($tenantId);

        if ($tenant === null) {
            return;
        }

        $limit = $this->entitlements->forTenant($tenantId, $now)->limit(FeatureCatalog::MAX_ORDERS_PER_MONTH);
        $grace = $this->graceDays($tenantId);
        $stores = Store::query()->where('tenant_id', $tenantId)->whereIn('status', ['onboarding', 'active', 'degraded'])->orderBy('id')->get();
        $over = [];
        $graceEnded = [];

        foreach ($stores as $store) {
            $result['stores']++;
            $figures = $this->figures($store, $now);
            $isOver = $limit !== null && $figures['average'] !== null && $figures['average'] > $limit;
            $overSince = $isOver ? ($store->order_volume_over_since ?? $now) : null;
            $store->forceFill([
                'order_volume_average' => $figures['average'],
                'order_volume_checked_at' => $now,
                'order_volume_over_since' => $overSince,
                'order_volume_notified_at' => $isOver ? $store->order_volume_notified_at : null,
            ])->save();

            if (! $isOver) {
                continue;
            }

            $result['over']++;
            $over[] = $store;

            if ($overSince->copy()->addDays($grace)->lessThanOrEqualTo($now)) {
                $graceEnded[] = $store;
            }

            if ($store->order_volume_notified_at === null && $this->tell($tenantId, 'order_volume_over', [
                'store' => $store->name,
                'average' => (int) $figures['average'],
                'limit' => (int) $limit,
                'date' => $overSince->copy()->addDays($grace)->toDateString(),
                'link' => $this->mailer->link('/app/settings/billing'),
            ])) {
                $store->forceFill(['order_volume_notified_at' => $now])->save();
                $result['notified']++;
            }
        }

        $wasPaused = $tenant->order_volume_paused_at !== null;
        $pause = $over !== [] && ($wasPaused || $graceEnded !== []);

        if ($pause && ! $wasPaused) {
            $tenant->forceFill(['order_volume_paused_at' => $now])->save();
            $this->entitlements->forget();
            $result['paused']++;
            $this->tell($tenantId, 'order_volume_paused', [
                'stores' => implode(', ', array_map(fn (Store $store): string => '«'.$store->name.'»', $graceEnded)),
                'limit' => (int) $limit,
                'link' => $this->mailer->link('/app/settings/billing'),
            ]);
        }

        if (! $pause && $wasPaused) {
            $tenant->forceFill(['order_volume_paused_at' => null])->save();
            $this->entitlements->forget();
            $result['resumed']++;

            foreach ($stores as $store) {
                $this->requeue->requeue($tenantId, $store->id, ReconciliationDirtySubject::REASON_ORDER_VOLUME_RESUMED);
            }
        }
    }

    /**
     * What the customer sees: every store's volume against the package, when it went over and until when it may stay.
     *
     * @return array<string, mixed>
     */
    public function summary(string $tenantId, ?string $currentPlanCode, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $limit = $this->entitlements->forTenant($tenantId, $now)->limit(FeatureCatalog::MAX_ORDERS_PER_MONTH);
        $grace = $this->graceDays($tenantId);
        $stores = Store::query()->where('tenant_id', $tenantId)->whereNotIn('status', ['deleted'])->orderBy('name')->get();
        $rows = [];
        $highest = 0;

        foreach ($stores as $store) {
            $figures = $this->figures($store, $now);
            $over = $store->order_volume_over_since;
            $highest = max($highest, (int) ($figures['average'] ?? 0));
            $rows[] = [
                'store_id' => $store->id,
                'name' => $store->name,
                'this_month' => $figures['this_month'],
                'average' => $figures['average'],
                'months' => $figures['months'],
                'over_since' => $over?->toJSON(),
                'grace_ends_at' => $over?->copy()->addDays($grace)->toJSON(),
            ];
        }

        $anyOver = collect($rows)->contains(fn (array $row): bool => $row['over_since'] !== null);

        return [
            'limit' => $limit,
            'grace_days' => $grace,
            'paused_at' => Tenant::query()->find($tenantId)?->order_volume_paused_at?->toJSON(),
            'stores' => $rows,
            'recommended' => $anyOver ? $this->recommended($highest, $currentPlanCode) : null,
        ];
    }

    public function graceDays(string $tenantId): int
    {
        $versionId = Subscription::query()->where('tenant_id', $tenantId)->value('plan_version_id');
        $days = $versionId === null ? null : BillingPlanVersion::query()->whereKey($versionId)->value('order_volume_grace_days');

        return $days === null ? 30 : (int) $days;
    }

    /**
     * The smallest published package whose order limit covers the volume (a package with an individual price last).
     *
     * @return array{plan_code: string, name: array<string, string>, contact_sales: bool}|null
     */
    public function recommended(int $average, ?string $currentPlanCode = null): ?array
    {
        $candidates = BillingPlanVersion::query()
            ->with('plan')
            ->where('state', BillingPlan::STATE_PUBLISHED)
            ->whereHas('plan', fn ($query) => $query->where('state', BillingPlan::STATE_PUBLISHED))
            ->get()
            ->filter(fn (BillingPlanVersion $version): bool => $version->plan->code !== $currentPlanCode && ! ($version->localized_content['placeholder'] ?? false))
            ->map(fn (BillingPlanVersion $version): array => ['version' => $version, 'limit' => FeatureCatalog::normalize($version->entitlements)['limits'][FeatureCatalog::MAX_ORDERS_PER_MONTH] ?? null])
            ->filter(fn (array $row): bool => $row['limit'] === null || $row['limit'] >= $average)
            ->sortBy(fn (array $row): array => [$row['version']->contact_sales ? 1 : 0, $row['limit'] ?? PHP_INT_MAX])
            ->first();

        return $candidates === null ? null : [
            'plan_code' => $candidates['version']->plan->code,
            'name' => $candidates['version']->localized_content['name'] ?? [],
            'contact_sales' => (bool) $candidates['version']->contact_sales,
        ];
    }

    /**
     * @param  array<string, string|int>  $replace
     */
    private function tell(string $tenantId, string $template, array $replace): bool
    {
        $owners = User::query()
            ->whereIn('id', Membership::query()->where('tenant_id', $tenantId)->where('role', TenantRoles::OWNER)->select('user_id'))
            ->get();
        $sent = false;

        foreach ($owners as $owner) {
            try {
                $this->mailer->send($owner->email, (string) $owner->locale, $template, $replace);
                $sent = true;
            } catch (Throwable $exception) {
                Log::warning('billing.order_volume_notify_failed', ['tenant_id' => $tenantId, 'template' => $template, 'exception' => $exception::class]);
            }
        }

        return $sent;
    }
}
