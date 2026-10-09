<?php

namespace App\Support\Checkout;

use App\Models\Incident;
use App\Models\PaymentAttemptWindow;
use App\Models\Signal;
use App\Support\Incidents\IncidentRecorder;
use Illuminate\Support\Carbon;

class PaymentAttemptMonitor
{
    public const FAMILY = 'checkout_payment';

    public const COMPONENT = 'payment_method';

    public const RULE_CODE = 'CHECKOUT_PAYMENTS_FAILING';

    public const RULE_VERSION = 'v1';

    public const CONFIG_VERSION = 1;

    public const FAILURE_STREAK_THRESHOLD = 3;

    public const LOOKBACK_DAYS = 7;

    public const MAX_WINDOWS = 2016;

    public function __construct(
        private readonly IncidentRecorder $incidents,
    ) {}

    public function evaluate(string $tenantId, string $storeId, string $paymentMethod): ?Incident
    {
        $state = $this->state($tenantId, $storeId, $paymentMethod);

        if ($state['latest'] === null) {
            return null;
        }

        $fingerprint = $this->fingerprint($storeId, $paymentMethod);

        if ($state['streak'] >= self::FAILURE_STREAK_THRESHOLD) {
            return $this->incidents->openOrAttach($tenantId, $storeId, $fingerprint, [
                'family' => self::FAMILY,
                'component' => self::COMPONENT,
                'title_code' => self::RULE_CODE,
                'severity' => Incident::SEVERITY_WARNING,
                'first_bad_at' => $state['first_failure_at'],
                'last_good_at' => $state['last_success_at'],
            ], $this->signal($tenantId, $storeId, $paymentMethod, $state, Signal::SEVERITY_WARNING), 'payment_method_failure_streak');
        }

        if ($state['last_success_at'] !== null && $this->incidents->active($tenantId, $storeId, $fingerprint) !== null) {
            return $this->incidents->resolve(
                $tenantId,
                $storeId,
                $fingerprint,
                'auto_resolved_successful_payment',
                $this->signal($tenantId, $storeId, $paymentMethod, $state, Signal::SEVERITY_INFO),
                $state['last_success_at'],
            );
        }

        return null;
    }

    /**
     * Consecutive non-successful outcomes after the most recent success, counted across windows:
     * windows without a success add all their failures, the window holding the last success adds
     * only the failures reported after that success.
     *
     * @return array{streak: int, last_success_at: ?Carbon, first_failure_at: ?Carbon, latest: ?PaymentAttemptWindow, failure_classes: array<string, int>}
     */
    public function state(string $tenantId, string $storeId, string $paymentMethod): array
    {
        $windows = PaymentAttemptWindow::query()
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where('payment_method', $paymentMethod)
            ->where('window_start', '>=', Carbon::now()->subDays(self::LOOKBACK_DAYS))
            ->orderByDesc('window_start')
            ->limit(self::MAX_WINDOWS)
            ->get();

        $streak = 0;
        $lastSuccessAt = null;
        $firstFailureAt = null;
        $classes = [];

        foreach ($windows as $window) {
            $failures = $window->successes() > 0 ? $window->trailing_failures : $window->failures();

            if ($failures > 0) {
                $streak += $failures;
                $firstFailureAt = $window->window_start;

                if ($window->successes() === 0) {
                    foreach ($window->failure_classes as $class => $count) {
                        $classes[$class] = ($classes[$class] ?? 0) + (int) $count;
                    }
                }
            }

            if ($window->successes() > 0) {
                $lastSuccessAt = $window->window_end;

                break;
            }
        }

        ksort($classes);

        return [
            'streak' => $streak,
            'last_success_at' => $lastSuccessAt,
            'first_failure_at' => $firstFailureAt,
            'latest' => $windows->first(),
            'failure_classes' => $classes,
        ];
    }

    /**
     * @param  array{streak: int, last_success_at: ?Carbon, first_failure_at: ?Carbon, latest: PaymentAttemptWindow, failure_classes: array<string, int>}  $state
     */
    private function signal(string $tenantId, string $storeId, string $paymentMethod, array $state, string $severity): Signal
    {
        $latest = $state['latest'];
        $now = Carbon::now();

        return Signal::query()->firstOrCreate(
            [
                'tenant_id' => $tenantId,
                'dedupe_key' => implode(':', ['payment_attempts', $storeId, $paymentMethod, $latest->window_start->toJSON(), $latest->source_revision, $state['streak'], $severity]),
            ],
            [
                'store_id' => $storeId,
                'signal_type' => Signal::TYPE_PAYMENT_ATTEMPTS,
                'family' => self::FAMILY,
                'component' => self::RULE_CODE,
                'severity' => $severity,
                'confidence' => Signal::CONFIDENCE_OBSERVED,
                'rule_version' => self::RULE_VERSION,
                'config_version' => self::CONFIG_VERSION,
                'observed_start' => $state['first_failure_at'] ?? $latest->window_start,
                'observed_end' => $latest->window_end,
                'evidence' => [
                    'payment_method' => $paymentMethod,
                    'failure_streak' => $state['streak'],
                    'threshold' => self::FAILURE_STREAK_THRESHOLD,
                    'failure_classes' => $state['failure_classes'],
                    'last_success_at' => $state['last_success_at']?->toJSON(),
                ],
                'data_quality' => ['source' => 'store_reported_checkout'],
                'detected_at' => $now,
            ],
        );
    }

    private function fingerprint(string $storeId, string $paymentMethod): string
    {
        return hash('sha256', implode('|', [self::FAMILY, self::COMPONENT, $storeId, $paymentMethod]));
    }
}
