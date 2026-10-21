<?php

namespace App\Support\Stores;

use App\Models\CheckAttempt;
use App\Models\CheckoutObservationWindow;
use App\Models\CheckRun;
use App\Models\CheckScenario;
use App\Models\CheckStep;
use App\Models\Incident;
use App\Models\Integration;
use App\Models\PaymentAttemptWindow;
use App\Models\Store;
use App\Support\Checkout\CheckoutLayout;
use App\Support\Checkout\CheckoutObservation;
use App\Support\Integrations\ConnectorFreshness;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What the service can and cannot currently see for each store. Every part states its own
 * coverage explicitly; nothing is reported as fine when the data needed to say so is missing.
 */
class StoreCoverage
{
    public const PAYMENT_ATTEMPTS_QUIET_HOURS = 24;

    public const CAPACITY_PAUSED = 'capacity_paused';

    /**
     * @param  Collection<int, Store>  $stores
     * @return array<string, array{coverage: array<string, mixed>, last_successful_check_at: ?string, active_incident_count: int}>
     */
    public function forStores(Collection $stores): array
    {
        if ($stores->isEmpty()) {
            return [];
        }

        $tenantId = $stores->first()->tenant_id;
        $storeIds = $stores->pluck('id')->all();
        $integrations = Integration::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('store_id', $storeIds)
            ->whereIn('status', [Integration::STATUS_ACTIVE, Integration::STATUS_DEGRADED])
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('store_id');
        $incidents = Incident::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('store_id', $storeIds)
            ->whereIn('state', Incident::ACTIVE_STATES)
            ->selectRaw('store_id, count(*) as aggregate')
            ->groupBy('store_id')
            ->pluck('aggregate', 'store_id');
        $lastWindow = PaymentAttemptWindow::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('store_id', $storeIds)
            ->selectRaw('store_id, max(window_end) as last_end')
            ->groupBy('store_id')
            ->pluck('last_end', 'store_id');
        $scenarios = CheckScenario::query()->where('tenant_id', $tenantId)->whereIn('store_id', $storeIds)->get()->keyBy('store_id');
        $scriptSeen = CheckoutObservationWindow::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('store_id', $storeIds)
            ->where('script_loaded', '>', 0)
            ->selectRaw('store_id, max(window_start) as last_seen')
            ->groupBy('store_id')
            ->pluck('last_seen', 'store_id');
        $lastRuns = collect($storeIds)->mapWithKeys(fn (string $storeId): array => [$storeId => CheckRun::query()
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->whereNotIn('status', CheckRun::ACTIVE_STATUSES)
            ->whereNotNull('finished_at')
            ->orderByDesc('finished_at')
            ->orderByDesc('id')
            ->first()]);
        $lastPassed = CheckRun::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('store_id', $storeIds)
            ->where('status', CheckRun::STATUS_PASSED)
            ->selectRaw('store_id, max(finished_at) as last_passed')
            ->groupBy('store_id')
            ->pluck('last_passed', 'store_id');

        $result = [];

        foreach ($stores as $store) {
            $storeIntegrations = $integrations->get($store->id, collect());
            $connector = $storeIntegrations->firstWhere('source_authority', Integration::SOURCE_STORE_REPORTED);
            $provider = $storeIntegrations->first(fn (Integration $integration): bool => $integration->source_authority === Integration::SOURCE_INDEPENDENT_PROVIDER && $integration->status === Integration::STATUS_ACTIVE);
            $paused = $store->capacity_paused_at !== null;
            $connectorState = match (true) {
                $connector === null => 'not_connected',
                $paused => self::CAPACITY_PAUSED,
                default => $connector->health['freshness']['state'] ?? ConnectorFreshness::STATE_WARMING_UP,
            };
            $storeDataStale = in_array($connectorState, ConnectorFreshness::DEGRADED_STATES, true);
            $windowEnd = $lastWindow->get($store->id);
            $passedAt = $lastPassed->get($store->id);

            $result[$store->id] = [
                'coverage' => [
                    'connector' => [
                        'state' => $connectorState,
                        'provider' => $connector?->provider,
                        'last_heartbeat_at' => $connector?->last_heartbeat_at?->toJSON(),
                        'last_sync_at' => $connector?->last_successful_sync_at?->toJSON(),
                        'plugin_version' => $connector?->health['heartbeat']['plugin_version'] ?? null,
                    ],
                    'money' => [
                        'state' => match (true) {
                            $provider === null => 'provider_not_connected',
                            $connector === null => 'store_not_connected',
                            $paused => self::CAPACITY_PAUSED,
                            $storeDataStale => 'store_data_stale',
                            default => 'reconciling',
                        },
                        'providers' => $storeIntegrations
                            ->where('source_authority', Integration::SOURCE_INDEPENDENT_PROVIDER)
                            ->map(fn (Integration $integration): array => ['provider' => $integration->provider, 'status' => $integration->status])
                            ->values()
                            ->all(),
                    ],
                    'payment_attempts' => [
                        'state' => match (true) {
                            $connector === null => 'store_not_connected',
                            $paused => self::CAPACITY_PAUSED,
                            $storeDataStale => 'store_data_stale',
                            $windowEnd === null => 'no_attempts_seen',
                            Carbon::parse($windowEnd)->lessThan(Carbon::now()->subHours(self::PAYMENT_ATTEMPTS_QUIET_HOURS)) => 'no_recent_attempts',
                            default => 'observing',
                        },
                        'last_window_end_at' => $windowEnd === null ? null : Carbon::parse($windowEnd)->toJSON(),
                    ],
                    'browser_checks' => $this->browserCoverage($store, $scenarios->get($store->id), $lastRuns->get($store->id)),
                    'checkout_script' => $this->scriptCoverage($store, $connector, $scriptSeen->get($store->id), $lastRuns->get($store->id)),
                ],
                'last_successful_check_at' => $passedAt === null ? null : Carbon::parse($passedAt)->toJSON(),
                'active_incident_count' => (int) $incidents->get($store->id, 0),
            ];
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * The checkout observation script of the Shopware app (ADR 0057) or the WooCommerce plugin (ADR 0059).
     *
     * @return array{state: string, last_report_at: ?string}
     */
    private function scriptCoverage(Store $store, ?Integration $connector, mixed $lastSeen, ?CheckRun $lastRun): array
    {
        if (! in_array($store->platform, ['shopware', 'woocommerce'], true)) {
            return ['state' => 'not_available', 'last_report_at' => null];
        }

        if ($connector === null || ! CheckoutObservation::carriesScript($connector)) {
            return ['state' => $store->platform === 'shopware' ? 'needs_app' : 'needs_plugin', 'last_report_at' => null];
        }

        $decision = app(CheckoutObservation::class)->decide($connector->id);
        $seenAt = $lastSeen === null ? null : Carbon::parse($lastSeen);
        $steps = $lastRun === null ? collect() : CheckStep::query()
            ->where('tenant_id', $store->tenant_id)
            ->whereIn('attempt_id', CheckAttempt::query()->where('run_id', $lastRun->id)->select('id'))
            ->where('step_code', 'payment_form')
            ->get();
        $failed = fn (string $code): bool => $steps->contains(fn (CheckStep $step): bool => collect($step->assertions)->contains(fn (array $assertion): bool => ($assertion['code'] ?? null) === $code && ($assertion['passed'] ?? true) === false));
        $missedByCheck = $failed('checkout_script_present');
        $elementsMissing = $failed('checkout_script_elements')
            || (CheckoutLayout::custom($store) !== [] && $steps->contains(fn (CheckStep $step): bool => $step->error_code === 'selector_changed'));

        return [
            'state' => match (true) {
                ! $decision['observe'] => $decision['reason'],
                $missedByCheck => 'not_seen_by_check',
                $elementsMissing => 'elements_not_found',
                $seenAt === null => 'waiting',
                $seenAt->lessThan(Carbon::now()->subHours(self::PAYMENT_ATTEMPTS_QUIET_HOURS)) => 'no_recent_reports',
                default => 'observing',
            },
            'last_report_at' => $seenAt?->toJSON(),
        ];
    }

    private function browserCoverage(Store $store, ?CheckScenario $scenario, ?CheckRun $lastRun): array
    {
        $state = match (true) {
            $store->verified_at === null => 'store_not_verified',
            $store->capacity_paused_at !== null => self::CAPACITY_PAUSED,
            ! $store->browser_enabled => 'disabled',
            $scenario === null => 'not_configured',
            ! $scenario->enabled => 'scenario_disabled',
            $lastRun === null => 'scheduled',
            $lastRun->status === CheckRun::STATUS_PASSED => 'passing',
            $lastRun->status === CheckRun::STATUS_FAILED => 'failing',
            default => 'not_conclusive',
        };

        return [
            'state' => $state,
            'last_run_status' => $lastRun?->status,
            'last_run_error_code' => $lastRun?->error_code,
            'last_run_finished_at' => $lastRun?->finished_at?->toJSON(),
            'next_due_at' => $scenario?->enabled && $store->capacity_paused_at === null ? $scenario->next_due_at?->toJSON() : null,
        ];
    }
}
