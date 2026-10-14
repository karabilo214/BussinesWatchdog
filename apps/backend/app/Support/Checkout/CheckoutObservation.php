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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The checkout observation script and render beacon of the Shopware app (ADR 0057): whether a store is observed, which
 * origins may report for it, and counters per 5-minute window. Nothing about a visitor is kept.
 */
class CheckoutObservation
{
    public const WINDOW_SECONDS = 300;

    public const PAGES = ['confirm', 'finish'];

    public const MAX_PER_WINDOW = 10000;

    public const MAX_JS_ERRORS_PER_REPORT = 20;

    public function __construct(
        private readonly ShopwareConnector $connector,
    ) {}

    /**
     * @return array{observe: bool, reason: string, integration: ?Integration}
     */
    public function decision(string $integrationId): array
    {
        $cached = Cache::remember('checkout-observation:decision:'.$integrationId, 60, fn (): array => $this->decide($integrationId));
        $integration = $cached['observe'] ? Integration::query()->find($integrationId) : null;

        return ['observe' => $cached['observe'] && $integration !== null, 'reason' => $cached['reason'], 'integration' => $integration];
    }

    /**
     * @return array{observe: bool, reason: string}
     */
    public function decide(string $integrationId): array
    {
        /** @var Integration|null $integration */
        $integration = preg_match('/^[0-9a-f-]{36}$/', $integrationId) === 1 ? Integration::query()->find($integrationId) : null;

        if ($integration === null || $integration->provider !== ShopwareConnector::PROVIDER || ($integration->capabilities['connection'] ?? null) !== 'app') {
            return ['observe' => false, 'reason' => 'not_a_shopware_app'];
        }

        if ($integration->status !== Integration::STATUS_ACTIVE) {
            return ['observe' => false, 'reason' => 'app_not_active'];
        }

        if (Store::query()->whereKey($integration->store_id)->where('status', 'active')->doesntExist()) {
            return ['observe' => false, 'reason' => 'store_not_active'];
        }

        $access = CheckScenario::query()->where('tenant_id', $integration->tenant_id)->where('store_id', $integration->store_id)->value('checkout_access');

        if (! in_array($access, [CheckScenario::ACCESS_TEST_CUSTOMER, CheckScenario::ACCESS_GUEST], true)) {
            return ['observe' => false, 'reason' => 'check_does_not_reach_payment'];
        }

        if (! app(EntitlementCalculator::class)->forTenant($integration->tenant_id)->allows(FeatureCatalog::PAYMENT_ATTEMPTS_SCRIPT)) {
            return ['observe' => false, 'reason' => 'not_in_plan'];
        }

        return ['observe' => true, 'reason' => 'observing'];
    }

    /**
     * Requests are accepted only from the shop itself: the host Shopware registered for the app or the store's address.
     */
    public function originAllowed(Integration $integration, ?string $origin, ?string $referer): bool
    {
        $host = strtolower((string) parse_url((string) ($origin ?: $referer), PHP_URL_HOST));

        return $host !== '' && in_array($host, $this->allowedHosts($integration), true);
    }

    public function render(Integration $integration, string $page, bool $javascript): void
    {
        if (! in_array($page, self::PAGES, true)) {
            return;
        }

        $this->increment($integration, [$page === 'confirm' && ! $javascript ? 'confirm_rendered_noscript' : $page.'_rendered' => 1]);
    }

    /**
     * @param  list<mixed>  $stages
     */
    public function stages(Integration $integration, string $page, array $stages, int $jsErrors): void
    {
        if (! in_array($page, self::PAGES, true)) {
            return;
        }

        $counts = [];

        foreach (array_unique(array_filter($stages, 'is_string')) as $stage) {
            if (in_array($stage, CheckoutObservationWindow::STAGES, true)) {
                $counts[$stage] = 1;
            }
        }

        if ($jsErrors > 0) {
            $counts['js_errors'] = min($jsErrors, self::MAX_JS_ERRORS_PER_REPORT);
        }

        if ($counts !== []) {
            $this->increment($integration, $counts);
        }
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function increment(Integration $integration, array $counts): void
    {
        $now = Carbon::now();
        $start = Carbon::createFromTimestampUTC($now->getTimestamp() - $now->getTimestamp() % self::WINDOW_SECONDS);

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

    /**
     * @return list<string>
     */
    private function allowedHosts(Integration $integration): array
    {
        return Cache::remember('checkout-observation:hosts:'.$integration->id, 600, function () use ($integration): array {
            $hosts = [strtolower((string) parse_url((string) Store::query()->whereKey($integration->store_id)->value('base_url'), PHP_URL_HOST))];
            $apiUrl = $this->connector->credentials($integration)['api_url'] ?? null;

            if (is_string($apiUrl)) {
                $hosts[] = strtolower((string) parse_url($apiUrl, PHP_URL_HOST));
            }

            return array_values(array_unique(array_filter($hosts)));
        });
    }
}
