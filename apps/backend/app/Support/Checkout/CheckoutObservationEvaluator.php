<?php

namespace App\Support\Checkout;

use App\Models\CheckoutObservationWindow;
use App\Models\Incident;
use App\Models\Integration;
use App\Models\ObservedPaymentAttempt;
use App\Models\PaymentAttemptWindow;
use App\Models\Signal;
use App\Support\Incidents\IncidentRecorder;
use App\Support\Platform\MaintenanceWindow;
use App\Support\Providers\Shopware\ShopwareConnector;
use Illuminate\Support\Carbon;

/**
 * Rules over the checkout observation counters (ADR 0057): the script is silent while confirm pages are shown, or
 * «Submit order» is sent but no order transaction (ADR 0056) and no finish page follows.
 */
class CheckoutObservationEvaluator
{
    public const FAMILY = 'checkout_observation';

    public const COMPONENT_SCRIPT = 'checkout_script';

    public const COMPONENT_SUBMIT = 'checkout_submit';

    public const RULE_SCRIPT_SILENT = 'CHECKOUT_SCRIPT_SILENT';

    public const RULE_SUBMIT_FAILING = 'CHECKOUT_SUBMIT_FAILING';

    public const RULE_VERSION = 'v1';

    public const CONFIG_VERSION = 1;

    public const SILENT_LOOKBACK_HOURS = 6;

    public const SILENT_MIN_PAGES = 5;

    public const SUBMIT_LOOKBACK_HOURS = 2;

    public const SUBMIT_MIN_SENDS = 3;

    public function __construct(
        private readonly IncidentRecorder $incidents,
        private readonly CheckoutObservation $observation,
    ) {}

    public function evaluateAll(): int
    {
        if (app(MaintenanceWindow::class)->active()) {
            return 0;
        }

        $count = 0;

        Integration::query()
            ->whereIn('provider', [ShopwareConnector::PROVIDER, CheckoutObservation::WOOCOMMERCE_PLUGIN])
            ->where('status', Integration::STATUS_ACTIVE)
            ->whereIn('store_id', CheckoutObservationWindow::query()->where('window_start', '>=', Carbon::now()->subHours(self::SILENT_LOOKBACK_HOURS))->select('store_id'))
            ->get()
            ->each(function (Integration $integration) use (&$count): void {
                if ($this->observation->decide($integration->id)['observe']) {
                    $this->evaluate($integration);
                    $count++;
                }
            });

        return $count;
    }

    public function evaluate(Integration $integration): void
    {
        $now = Carbon::now();
        $silent = $this->totals($integration, $now->copy()->subHours(self::SILENT_LOOKBACK_HOURS));
        $pages = max(0, $silent['confirm_rendered'] - $silent['confirm_rendered_noscript']);

        if ($pages >= self::SILENT_MIN_PAGES && $silent['script_loaded'] === 0) {
            $this->open($integration, self::COMPONENT_SCRIPT, self::RULE_SCRIPT_SILENT, Incident::SEVERITY_INFO, ['pages' => $pages, 'hours' => self::SILENT_LOOKBACK_HOURS]);
        } elseif ($this->totals($integration, $now->copy()->subHour())['script_loaded'] > 0) {
            $this->resolve($integration, self::COMPONENT_SCRIPT, 'auto_resolved_script_reported');
        }

        $firstSend = CheckoutObservationWindow::query()
            ->where('tenant_id', $integration->tenant_id)
            ->where('store_id', $integration->store_id)
            ->where('window_start', '>=', $now->copy()->subHours(self::SUBMIT_LOOKBACK_HOURS))
            ->where('submit_clicked', '>', 0)
            ->min('window_start');

        if ($firstSend === null) {
            if ($this->attemptsSince($integration, $now->copy()->subHours(self::SUBMIT_LOOKBACK_HOURS)) > 0) {
                $this->resolve($integration, self::COMPONENT_SUBMIT, 'auto_resolved_order_placed');
            }

            return;
        }

        $since = Carbon::parse($firstSend);
        $submit = $this->totals($integration, $since->copy()->addSeconds(CheckoutObservation::WINDOW_SECONDS));
        $attempts = $this->attemptsSince($integration, $since);

        if ($submit['submit_clicked'] >= self::SUBMIT_MIN_SENDS && $submit['finish_reached'] === 0 && $attempts === 0) {
            $this->open($integration, self::COMPONENT_SUBMIT, self::RULE_SUBMIT_FAILING, Incident::SEVERITY_WARNING, ['sends' => $submit['submit_clicked'], 'stalled' => $submit['submit_stalled'], 'returned_with_error' => $submit['returned_with_error'], 'hours' => self::SUBMIT_LOOKBACK_HOURS]);
        } elseif ($submit['finish_reached'] > 0 || $attempts > 0) {
            $this->resolve($integration, self::COMPONENT_SUBMIT, 'auto_resolved_order_placed');
        }
    }

    /**
     * Real payment attempts (orders with a payment) since a time: observed by the service (Shopware, ADR 0056) or
     * reported by the WooCommerce plugin in windows (ADR 0007), whose windows count from the window start.
     */
    private function attemptsSince(Integration $integration, Carbon $since): int
    {
        $observed = ObservedPaymentAttempt::query()->where('tenant_id', $integration->tenant_id)->where('store_id', $integration->store_id)->where('attempted_at', '>=', $since)->count();
        $windowStart = Carbon::createFromTimestampUTC($since->getTimestamp() - $since->getTimestamp() % CheckoutObservation::WINDOW_SECONDS);
        $reported = (int) PaymentAttemptWindow::query()
            ->where('tenant_id', $integration->tenant_id)
            ->where('store_id', $integration->store_id)
            ->where('window_start', '>=', $windowStart)
            ->selectRaw('coalesce(sum(paid + on_hold + failed + pending_stuck + late_success), 0) as attempts')
            ->value('attempts');

        return $observed + $reported;
    }

    /**
     * @return array<string, int>
     */
    private function totals(Integration $integration, Carbon $since): array
    {
        $row = CheckoutObservationWindow::query()
            ->where('tenant_id', $integration->tenant_id)
            ->where('store_id', $integration->store_id)
            ->where('window_start', '>=', $since->copy()->subSeconds(CheckoutObservation::WINDOW_SECONDS))
            ->selectRaw(implode(', ', array_map(fn (string $counter): string => "coalesce(sum({$counter}), 0) as {$counter}", CheckoutObservationWindow::COUNTERS)))
            ->first();

        return array_map('intval', array_intersect_key($row?->getAttributes() ?? [], array_flip(CheckoutObservationWindow::COUNTERS))) + array_fill_keys(CheckoutObservationWindow::COUNTERS, 0);
    }

    /**
     * @param  array<string, int>  $evidence
     */
    private function open(Integration $integration, string $component, string $rule, string $severity, array $evidence): void
    {
        $this->incidents->openOrAttach($integration->tenant_id, $integration->store_id, $this->fingerprint($integration->store_id, $component), [
            'family' => self::FAMILY,
            'component' => $component,
            'title_code' => $rule,
            'severity' => $severity,
            'first_bad_at' => Carbon::now(),
            'last_good_at' => null,
        ], $this->signal($integration, $rule, $severity === Incident::SEVERITY_INFO ? Signal::SEVERITY_INFO : Signal::SEVERITY_WARNING, $evidence), strtolower($rule));
    }

    private function resolve(Integration $integration, string $component, string $reason): void
    {
        $fingerprint = $this->fingerprint($integration->store_id, $component);

        if ($this->incidents->active($integration->tenant_id, $integration->store_id, $fingerprint) === null) {
            return;
        }

        $rule = $component === self::COMPONENT_SCRIPT ? self::RULE_SCRIPT_SILENT : self::RULE_SUBMIT_FAILING;
        $this->incidents->resolve($integration->tenant_id, $integration->store_id, $fingerprint, $reason, $this->signal($integration, $rule, Signal::SEVERITY_INFO, []), Carbon::now());
    }

    /**
     * @param  array<string, int>  $evidence
     */
    private function signal(Integration $integration, string $rule, string $severity, array $evidence): Signal
    {
        $now = Carbon::now();
        $window = $now->getTimestamp() - $now->getTimestamp() % CheckoutObservation::WINDOW_SECONDS;

        return Signal::query()->firstOrCreate(
            ['tenant_id' => $integration->tenant_id, 'dedupe_key' => implode(':', ['checkout_observation', $integration->store_id, $rule, $severity, $window])],
            [
                'store_id' => $integration->store_id,
                'signal_type' => Signal::TYPE_CHECKOUT_OBSERVATION,
                'family' => self::FAMILY,
                'component' => $rule,
                'severity' => $severity,
                'confidence' => Signal::CONFIDENCE_OBSERVED,
                'rule_version' => self::RULE_VERSION,
                'config_version' => self::CONFIG_VERSION,
                'observed_start' => $now->copy()->subHours($evidence['hours'] ?? 1),
                'observed_end' => $now,
                'evidence' => $evidence,
                'data_quality' => ['source' => 'checkout_observation_script'],
                'detected_at' => $now,
            ],
        );
    }

    private function fingerprint(string $storeId, string $component): string
    {
        return hash('sha256', implode('|', [self::FAMILY, $component, $storeId]));
    }
}
