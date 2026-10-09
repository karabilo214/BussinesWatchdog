<?php

namespace App\Support\Checkout;

use App\Models\Incident;
use App\Models\IncidentActivity;
use App\Models\IncidentSignal;
use App\Models\NotificationDelivery;
use App\Models\PaymentAttemptWindow;
use App\Models\Signal;
use App\Support\Notifications\IncidentNotificationRequester;
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

    public const REOPEN_WINDOW_HOURS = 24;

    public function __construct(
        private readonly IncidentNotificationRequester $notifications,
    ) {}

    public function evaluate(string $tenantId, string $storeId, string $paymentMethod): ?Incident
    {
        $state = $this->state($tenantId, $storeId, $paymentMethod);

        if ($state['latest'] === null) {
            return null;
        }

        if ($state['streak'] >= self::FAILURE_STREAK_THRESHOLD) {
            return $this->openOrAttach($tenantId, $storeId, $paymentMethod, $state);
        }

        if ($state['last_success_at'] !== null) {
            return $this->maybeResolve($tenantId, $storeId, $paymentMethod, $state);
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
    private function openOrAttach(string $tenantId, string $storeId, string $paymentMethod, array $state): Incident
    {
        $fingerprint = $this->fingerprint($storeId, $paymentMethod);
        $now = Carbon::now();
        $notificationKind = null;

        $incident = $this->activeIncident($tenantId, $storeId, $fingerprint);

        if ($incident === null) {
            $recentlyResolved = Incident::query()
                ->where('tenant_id', $tenantId)
                ->where('store_id', $storeId)
                ->where('fingerprint', $fingerprint)
                ->where('state', Incident::STATE_RESOLVED)
                ->where('resolved_at', '>=', $now->copy()->subHours(self::REOPEN_WINDOW_HOURS))
                ->orderByDesc('resolved_at')
                ->lockForUpdate()
                ->first();

            if ($recentlyResolved !== null) {
                $recentlyResolved->forceFill([
                    'state' => Incident::STATE_OPEN,
                    'resolved_at' => null,
                    'resolution_reason' => null,
                    'last_seen_at' => $now,
                    'revision' => $recentlyResolved->revision + 1,
                    'updated_at' => $now,
                ])->save();
                $this->writeActivity($recentlyResolved, IncidentActivity::KIND_REOPENED, []);
                $incident = $recentlyResolved;
                $notificationKind = NotificationDelivery::KIND_INCIDENT_REOPENED;
            }
        }

        $signal = $this->signal($tenantId, $storeId, $paymentMethod, $state, Signal::SEVERITY_WARNING, $now);

        if ($incident === null) {
            $incident = Incident::query()->create([
                'tenant_id' => $tenantId,
                'store_id' => $storeId,
                'family' => self::FAMILY,
                'component' => self::COMPONENT,
                'fingerprint' => $fingerprint,
                'state' => Incident::STATE_OPEN,
                'severity' => Incident::SEVERITY_WARNING,
                'title_code' => self::RULE_CODE,
                'currency' => null,
                'verified_discrepancy_minor' => null,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'last_good_at' => $state['last_success_at'],
                'first_bad_at' => $state['first_failure_at'] ?? $now,
                'revision' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->writeActivity($incident, IncidentActivity::KIND_CREATED, []);
            $notificationKind = NotificationDelivery::KIND_INCIDENT_OPENED;
        } elseif ($notificationKind === null) {
            $incident->forceFill([
                'last_seen_at' => $now,
                'revision' => $incident->revision + 1,
                'updated_at' => $now,
            ])->save();
            $this->writeActivity($incident, IncidentActivity::KIND_SIGNAL_LINKED, []);
        }

        $this->link($incident, $signal, 'payment_method_failure_streak', $now);

        if ($notificationKind !== null) {
            $this->notifications->request($incident, $notificationKind);
        }

        return $incident;
    }

    /**
     * @param  array{streak: int, last_success_at: ?Carbon, first_failure_at: ?Carbon, latest: PaymentAttemptWindow, failure_classes: array<string, int>}  $state
     */
    private function maybeResolve(string $tenantId, string $storeId, string $paymentMethod, array $state): ?Incident
    {
        $incident = $this->activeIncident($tenantId, $storeId, $this->fingerprint($storeId, $paymentMethod));

        if ($incident === null) {
            return null;
        }

        $now = Carbon::now();
        $signal = $this->signal($tenantId, $storeId, $paymentMethod, $state, Signal::SEVERITY_INFO, $now);

        $incident->forceFill([
            'state' => Incident::STATE_RESOLVED,
            'resolved_at' => $now,
            'resolution_reason' => 'auto_resolved_successful_payment',
            'last_good_at' => $state['last_success_at'],
            'last_seen_at' => $now,
            'revision' => $incident->revision + 1,
            'updated_at' => $now,
        ])->save();

        $this->link($incident, $signal, 'successful_payment_observed', $now);
        $this->writeActivity($incident, IncidentActivity::KIND_RESOLVED, ['reason' => 'auto_resolved_successful_payment']);
        $this->notifications->request($incident, NotificationDelivery::KIND_INCIDENT_RECOVERED);

        return $incident;
    }

    private function activeIncident(string $tenantId, string $storeId, string $fingerprint): ?Incident
    {
        return Incident::query()
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where('fingerprint', $fingerprint)
            ->whereIn('state', Incident::ACTIVE_STATES)
            ->lockForUpdate()
            ->first();
    }

    /**
     * @param  array{streak: int, last_success_at: ?Carbon, first_failure_at: ?Carbon, latest: PaymentAttemptWindow, failure_classes: array<string, int>}  $state
     */
    private function signal(string $tenantId, string $storeId, string $paymentMethod, array $state, string $severity, Carbon $now): Signal
    {
        $latest = $state['latest'];

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

    private function link(Incident $incident, Signal $signal, string $reason, Carbon $now): void
    {
        IncidentSignal::query()->firstOrCreate(
            ['incident_id' => $incident->id, 'signal_id' => $signal->id],
            ['tenant_id' => $incident->tenant_id, 'store_id' => $incident->store_id, 'association_reason' => $reason, 'linked_at' => $now],
        );
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function writeActivity(Incident $incident, string $kind, array $extra): void
    {
        IncidentActivity::query()->create([
            'tenant_id' => $incident->tenant_id,
            'store_id' => $incident->store_id,
            'incident_id' => $incident->id,
            'kind' => $kind,
            'actor_id' => null,
            'incident_revision' => $incident->revision,
            'sanitized_data' => $extra,
            'created_at' => Carbon::now(),
        ]);
    }

    private function fingerprint(string $storeId, string $paymentMethod): string
    {
        return hash('sha256', implode('|', [self::FAMILY, self::COMPONENT, $storeId, $paymentMethod]));
    }
}
