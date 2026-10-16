<?php

namespace App\Support\Checkout;

use App\Models\CheckoutObservationWindow;
use App\Models\CheckScenario;
use App\Models\Integration;
use App\Models\Store;
use App\Support\Billing\EntitlementCalculator;
use App\Support\Billing\FeatureCatalog;
use App\Support\Providers\Shopware\ShopwareConnector;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Checkout observation (ADR 0057/0062 Shopware app, ADR 0059 WooCommerce plugin): whether a store is observed and
 * counters per 5-minute window. Nothing about a visitor is kept.
 */
class CheckoutObservation
{
    public const WINDOW_SECONDS = 300;

    public const PAGES = ['confirm', 'finish'];

    public const MAX_PER_WINDOW = 10000;

    public const MAX_JS_ERRORS_PER_REPORT = 20;

    public const WOOCOMMERCE_PLUGIN = 'woocommerce';

    /**
     * @return array{observe: bool, reason: string}
     */
    public function decide(string $integrationId): array
    {
        /** @var Integration|null $integration */
        $integration = preg_match('/^[0-9a-f-]{36}$/', $integrationId) === 1 ? Integration::query()->find($integrationId) : null;

        if ($integration === null || ! self::carriesScript($integration)) {
            return ['observe' => false, 'reason' => 'connection_without_script'];
        }

        if ($integration->status !== Integration::STATUS_ACTIVE) {
            return ['observe' => false, 'reason' => 'app_not_active'];
        }

        if (Store::query()->whereKey($integration->store_id)->where('status', 'active')->doesntExist()) {
            return ['observe' => false, 'reason' => 'store_not_active'];
        }

        if ($integration->provider === ShopwareConnector::PROVIDER) {
            $access = CheckScenario::query()->where('tenant_id', $integration->tenant_id)->where('store_id', $integration->store_id)->value('checkout_access');

            if (! in_array($access, [CheckScenario::ACCESS_TEST_CUSTOMER, CheckScenario::ACCESS_GUEST], true)) {
                return ['observe' => false, 'reason' => 'check_does_not_reach_payment'];
            }
        }

        if (! app(EntitlementCalculator::class)->forTenant($integration->tenant_id)->allows(FeatureCatalog::PAYMENT_ATTEMPTS_SCRIPT)) {
            return ['observe' => false, 'reason' => 'not_in_plan'];
        }

        return ['observe' => true, 'reason' => 'observing'];
    }

    /**
     * Connections that put the observation script on the store's checkout: the Shopware app (ADR 0057) and the
     * WooCommerce plugin (ADR 0059). An API key alone cannot.
     */
    public static function carriesScript(Integration $integration): bool
    {
        return $integration->provider === self::WOOCOMMERCE_PLUGIN
            || ($integration->provider === ShopwareConnector::PROVIDER && ($integration->capabilities['connection'] ?? null) === 'app');
    }

    /**
     * Counters of one report the Shopware app stored in the shop (ADR 0062): which pages were shown and which stages the
     * script saw. Unknown pages and stages are ignored; script errors are capped per report.
     *
     * @return array<string, int>
     */
    public static function reportCounts(string $page, string $stages, int $jsErrors): array
    {
        if (! in_array($page, self::PAGES, true)) {
            return [];
        }

        $counts = [];

        foreach (array_unique(explode(',', $stages)) as $stage) {
            $counter = match (true) {
                $stage === 'rendered' => $page.'_rendered',
                $stage === 'rendered_noscript' && $page === 'confirm' => 'confirm_rendered_noscript',
                in_array($stage, CheckoutObservationWindow::STAGES, true) => $stage,
                default => null,
            };

            if ($counter !== null) {
                $counts[$counter] = 1;
            }
        }

        if ($jsErrors > 0) {
            $counts['js_errors'] = min($jsErrors, self::MAX_JS_ERRORS_PER_REPORT);
        }

        return $counts;
    }

    /**
     * Adds counters to the store's window that starts at $start; each counter is capped per window.
     *
     * @param  array<string, int>  $counts
     */
    public function add(Integration $integration, Carbon $start, array $counts): void
    {
        $now = Carbon::now();

        DB::transaction(function () use ($integration, $counts, $now, $start): void {
            /** @var CheckoutObservationWindow|null $window */
            $window = CheckoutObservationWindow::query()
                ->where('store_id', $integration->store_id)
                ->where('window_start', $start)
                ->lockForUpdate()
                ->first();

            $window ??= CheckoutObservationWindow::query()->firstOrCreate(
                ['store_id' => $integration->store_id, 'window_start' => $start],
                ['tenant_id' => $integration->tenant_id, 'integration_id' => $integration->id, 'created_at' => $now, 'updated_at' => $now],
            );

            foreach ($counts as $counter => $count) {
                $window->{$counter} = min(self::MAX_PER_WINDOW, (int) $window->{$counter} + $count);
            }

            $window->updated_at = $now;
            $window->save();
        });
    }

    public static function windowStart(Carbon $at): Carbon
    {
        return Carbon::createFromTimestampUTC($at->getTimestamp() - $at->getTimestamp() % self::WINDOW_SECONDS);
    }
}
