<?php

namespace App\Support\Billing;

use App\Models\BillingPlan;
use App\Models\BillingPlanVersion;
use App\Models\Membership;
use App\Models\Order;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Account\AccountMailer;
use App\Support\Auth\TenantRoles;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Order volume of a store for volume-based packages (ADR 0041): every real order is counted once, in the month it was
 * placed, when the service first sees it — repeated syncs, status changes and refunds never count, and neither do
 * orders placed before the store was added (the history import). A store is over its package when the average of
 * the last three complete months exceeds `max_orders_per_month`; the owner is told once and gets the package's grace
 * period to move to a bigger package. Nothing is switched off and nothing is charged automatically.
 */
class OrderVolume
{
    public const AVERAGE_MONTHS = 3;

    public function __construct(
        private readonly EntitlementCalculator $entitlements,
        private readonly AccountMailer $mailer,
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
     * Daily: refreshes every store's average and its over-the-package state; notifies owners once per crossing.
     *
     * @return array{stores: int, over: int, notified: int}
     */
    public function evaluate(?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $result = ['stores' => 0, 'over' => 0, 'notified' => 0];

        Store::query()->whereIn('status', ['onboarding', 'active', 'degraded'])->orderBy('id')->chunkById(200, function ($stores) use ($now, &$result): void {
            foreach ($stores as $store) {
                $result['stores']++;
                $figures = $this->figures($store, $now);
                $limit = $this->entitlements->forTenant($store->tenant_id, $now)->limit(FeatureCatalog::MAX_ORDERS_PER_MONTH);
                $over = $limit !== null && $figures['average'] !== null && $figures['average'] > $limit;
                $store->forceFill([
                    'order_volume_average' => $figures['average'],
                    'order_volume_checked_at' => $now,
                    'order_volume_over_since' => $over ? ($store->order_volume_over_since ?? $now) : null,
                    'order_volume_notified_at' => $over ? $store->order_volume_notified_at : null,
                ])->save();

                if (! $over) {
                    continue;
                }

                $result['over']++;

                if ($store->order_volume_notified_at === null && $this->notify($store, (int) $limit, (int) $figures['average'], $now)) {
                    $store->forceFill(['order_volume_notified_at' => $now])->save();
                    $result['notified']++;
                }
            }
        });

        return $result;
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

    private function notify(Store $store, int $limit, int $average, Carbon $now): bool
    {
        $owners = User::query()
            ->whereIn('id', Membership::query()->where('tenant_id', $store->tenant_id)->where('role', TenantRoles::OWNER)->select('user_id'))
            ->get();
        $graceEnds = $now->copy()->addDays($this->graceDays($store->tenant_id));
        $sent = false;

        foreach ($owners as $owner) {
            try {
                $this->mailer->send($owner->email, (string) $owner->locale, 'order_volume_over', [
                    'store' => $store->name,
                    'average' => $average,
                    'limit' => $limit,
                    'date' => $graceEnds->toDateString(),
                    'link' => $this->mailer->link('/app/settings/billing'),
                ]);
                $sent = true;
            } catch (Throwable $exception) {
                Log::warning('billing.order_volume_notify_failed', ['store_id' => $store->id, 'exception' => $exception::class]);
            }
        }

        return $sent;
    }
}
