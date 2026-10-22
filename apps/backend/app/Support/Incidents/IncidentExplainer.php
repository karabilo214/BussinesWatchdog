<?php

namespace App\Support\Incidents;

use App\Models\CheckRun;
use App\Models\Incident;
use App\Models\IncidentSignal;
use App\Models\Integration;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ReconciliationFinding;
use App\Models\Signal;
use App\Support\Browser\CheckOutcomeEvaluator;
use App\Support\Integrations\ConnectorFreshness;
use App\Support\Integrations\ProviderCoverage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Turns what the service already knows about an incident into an explanation for the store owner: the established
 * fact, the evidence it rests on, the possible causes that fit this case, what to check first, how fresh the data is
 * and what changed after the last check. Only codes and values are returned; the texts are templates in the cabinet
 * and the notifications (ru/en/de). A missing reference or amount changes the codes, it never leaves a gap. Nothing
 * here is a new conclusion: a mismatch stays a mismatch, never a loss, and no step ever asks to charge or refund again.
 */
class IncidentExplainer
{
    public const SCENARIO_CAPTURE_UNCONFIRMED = 'capture_unconfirmed';

    public const SCENARIO_CAPTURE_NOT_RECORDED = 'capture_not_recorded';

    public const SCENARIO_REFUND_UNCONFIRMED = 'refund_unconfirmed';

    public const SCENARIO_BROWSER_CHECK_STOPPED = 'browser_check_stopped';

    public const SCENARIO_SOURCE_UNAVAILABLE = 'source_unavailable';

    private const PROVIDER_SYNC_STALE_HOURS = 24;

    /** Every code an explanation can carry in these lists; each has a text in the cabinet (ru/en/de). */
    public const TEXT_CODES = [
        'cause' => [
            'authorized_not_captured',
            'capture_pending',
            'key_permissions_changed',
            'key_revoked_or_changed',
            'marked_paid_early',
            'notification_not_processed',
            'page_changed',
            'payment_failed_but_marked_paid',
            'plugin_disabled',
            'plugin_not_active',
            'provider_data_incomplete',
            'reference_mismatch',
            'reference_missing',
            'refund_failed_at_provider',
            'refund_marked_only',
            'refund_partial_at_provider',
            'scheduler_slow_or_stopped',
            'site_down',
            'site_overloaded',
            'site_unreachable',
            'status_changed_manually',
            'step_failed_on_site',
            'store_marked_failed_after_payment',
            'temporary_outage',
        ],
        'step' => [
            'check_captured_not_authorized',
            'check_connection',
            'check_key_permissions',
            'check_order_status',
            'check_payment_module_data',
            'check_payment_module_notifications',
            'check_plugin_active',
            'check_recent_changes',
            'check_refund_at_provider',
            'check_scheduler',
            'check_site_reachable',
            'compare_reference',
            'find_capture_in_provider',
            'find_payment_in_provider',
            'link_manually',
            'mark_paid_without_recharge',
            'open_capture_in_provider',
            'open_check_run',
            'open_payment_by_reference',
            'recheck',
            'refund_in_provider_dashboard',
            'repeat_path_manually',
            'replace_key',
            'restore_provider_access',
            'run_check_again',
            'send_refund_through_provider_if_missing',
        ],
        'warning' => [
            'browser_check_no_payment',
            'no_double_refund',
            'no_recharge',
            'source_gap_not_sales_stop',
        ],
        'auto_close' => [
            'capture_linked_or_unmarked',
            'provider_refund_matches',
            'scheduled_checks_pass',
            'source_fresh_again',
            'store_marks_paid',
        ],
    ];

    public function __construct(private readonly ConnectorFreshness $freshness) {}

    /**
     * @return array<string, mixed>
     */
    public function explain(Incident $incident, bool $showMoneyAmounts = true): array
    {
        $explanation = match (true) {
            $incident->family === 'money' => $this->money($incident, $showMoneyAmounts),
            $incident->title_code === CheckOutcomeEvaluator::TITLE_FLOW_FAILED => $this->browserCheck($incident),
            $incident->family === ConnectorFreshness::FAMILY => $this->source($incident),
            default => ['scenario' => null, 'observation' => null],
        };

        return $explanation + $this->state($incident, $explanation['observation'] ?? null) + ['technical' => $explanation['technical'] ?? ['rule_code' => $incident->title_code]];
    }

    /**
     * @return array<string, mixed>
     */
    private function money(Incident $incident, bool $showMoneyAmounts): array
    {
        $finding = $this->latestFinding($incident);
        /** @var Order|null $order */
        $order = $finding?->order_id === null ? null : Order::query()->where('tenant_id', $incident->tenant_id)->whereKey($finding->order_id)->first();
        $provider = ProviderCoverage::providerForGateway($order?->gateway);
        $observation = $this->providerObservation($incident, $provider);
        $scenario = match ($incident->title_code) {
            ReconciliationFinding::RULE_CAPTURE_MISSING, ReconciliationFinding::RULE_PAYMENT_NOT_MATCHED => self::SCENARIO_CAPTURE_UNCONFIRMED,
            ReconciliationFinding::RULE_CAPTURE_NOT_RECORDED => self::SCENARIO_CAPTURE_NOT_RECORDED,
            ReconciliationFinding::RULE_REFUND_MISSING => self::SCENARIO_REFUND_UNCONFIRMED,
            default => null,
        };
        $exponent = $finding?->currency_exponent ?? $order?->currency_exponent;
        $amount = fn (mixed $minor): ?array => $showMoneyAmounts && $minor !== null && $exponent !== null && $order !== null
            ? ['minor' => (string) $minor, 'currency' => $order->currency, 'exponent' => (int) $exponent]
            : null;
        $payment = $order === null ? null : $this->referencedPayment($order);
        $captureIds = array_values(array_filter((array) ($finding?->evidence['capture_operation_ids'] ?? []), 'is_string'));
        $params = array_filter([
            'order' => $order?->display_number,
            'provider' => $provider,
            'reference' => ($order?->transaction_ref ?? '') !== '' ? $order->transaction_ref : null,
        ], fn (mixed $value): bool => $value !== null);
        $common = [
            'observation' => $observation,
            'technical' => array_filter([
                'rule_code' => $incident->title_code,
                'reason_code' => $finding?->reason_code,
                'order_reference' => $params['reference'] ?? null,
                'store_transaction_ref' => $order?->metadata['store_transaction_ref'] ?? null,
                'payment_intent' => $payment?->intent_ref,
                'charge' => $payment?->charge_ref,
                'capture_operation_ids' => $captureIds === [] ? null : $captureIds,
                'source' => 'reconciliation',
            ], fn (mixed $value): bool => $value !== null),
        ];
        $lastSync = ['item' => 'last_provider_sync', 'value' => $observation['checked_at'] === null ? 'never' : 'at', 'params' => array_filter(['at' => $observation['checked_at'], 'provider' => $provider])];
        $completeness = ['item' => 'data_completeness', 'value' => $observation['state'] === 'fresh' ? 'full' : 'limited', 'params' => array_filter(['reason' => $observation['reason']])];
        $incomplete = $observation['state'] !== 'fresh';

        if ($scenario === self::SCENARIO_CAPTURE_UNCONFIRMED) {
            $hasReference = isset($params['reference']);
            $causes = match (true) {
                $payment?->status === 'authorized' => ['authorized_not_captured'],
                $payment?->status === 'pending' => ['capture_pending'],
                in_array($payment?->status, ['failed', 'cancelled'], true) => ['payment_failed_but_marked_paid'],
                $hasReference => ['marked_paid_early', 'reference_mismatch'],
                default => ['reference_missing', 'marked_paid_early'],
            };
            $steps = $hasReference
                ? ['open_payment_by_reference', 'check_captured_not_authorized', 'compare_reference', 'recheck']
                : ['check_payment_module_data', 'find_payment_in_provider', 'link_manually', 'recheck'];

            return [
                'scenario' => $scenario,
                'variant' => $hasReference ? 'with_reference' : 'without_reference',
                'params' => $params + array_filter(['amount' => $amount($finding?->gross_minor ?? $order?->total_minor)]),
                'evidence' => [
                    $this->storeOrderRow($order, $amount($order?->total_minor)),
                    ['item' => 'paid_mark', 'value' => $order?->paid_marked_at === null ? 'no' : 'yes', 'params' => array_filter(['at' => $order?->paid_marked_at?->toJSON()])],
                    ['item' => 'payment_reference', 'value' => $hasReference ? 'from_order' : 'missing', 'params' => array_filter(['reference' => $params['reference'] ?? null])],
                    ['item' => 'provider_payment', 'value' => $payment === null ? 'not_found' : 'found', 'params' => array_filter(['status' => $payment?->status])],
                    ['item' => 'successful_capture', 'value' => 'not_confirmed', 'params' => []],
                    $lastSync,
                    $completeness,
                ],
                'causes' => [...$causes, ...($incomplete ? ['provider_data_incomplete'] : [])],
                'steps' => [...($observation['state'] === 'unavailable' ? ['restore_provider_access'] : []), ...$steps],
                'warning' => 'no_recharge',
                'auto_close' => 'capture_linked_or_unmarked',
            ] + $common;
        }

        if ($scenario === self::SCENARIO_CAPTURE_NOT_RECORDED) {
            $storeState = $order?->metadata['payment_state'] ?? null;
            $captured = $finding?->captured_minor ?? $finding?->actual_minor;

            return [
                'scenario' => $scenario,
                'variant' => $captureIds === [] ? 'without_reference' : 'with_reference',
                'params' => $params + array_filter(['amount' => $amount($captured), 'capture' => $captureIds[0] ?? null, 'store_state' => $storeState]),
                'evidence' => [
                    $this->storeOrderRow($order, $amount($order?->total_minor)),
                    ['item' => 'provider_capture', 'value' => $amount($captured) === null ? 'confirmed_no_amount' : 'confirmed', 'params' => array_filter(['amount' => $amount($captured), 'capture' => $captureIds[0] ?? null])],
                    ['item' => 'paid_mark', 'value' => 'no', 'params' => []],
                    ['item' => 'store_payment_state', 'value' => $storeState === null ? 'unknown' : 'state', 'params' => array_filter(['state' => $storeState])],
                    $lastSync,
                ],
                'causes' => in_array($storeState, ['failed', 'cancelled'], true) ? ['store_marked_failed_after_payment'] : ['notification_not_processed', 'status_changed_manually'],
                'steps' => [$captureIds === [] ? 'find_capture_in_provider' : 'open_capture_in_provider', 'check_order_status', 'check_payment_module_notifications', 'mark_paid_without_recharge', 'recheck'],
                'warning' => 'no_recharge',
                'auto_close' => 'store_marks_paid',
            ] + $common;
        }

        if ($scenario === self::SCENARIO_REFUND_UNCONFIRMED) {
            $refundState = $order?->metadata['refund_state'] ?? null;
            $expected = $finding?->refund_expected_minor;
            $actual = (int) ($finding?->refund_actual_minor ?? 0);
            $appCannotRefund = ($order?->metadata['provider_refunds_synced'] ?? true) === false;

            return [
                'scenario' => $scenario,
                'variant' => $appCannotRefund ? 'payment_app_without_refunds' : 'default',
                'params' => $params + array_filter(['expected' => $amount($expected), 'actual' => $amount($actual), 'store_state' => $refundState]),
                'evidence' => [
                    $this->storeOrderRow($order, $amount($order?->total_minor)),
                    ['item' => 'store_refund', 'value' => $expected !== null ? 'amount' : ($refundState === 'partial' ? 'partial_no_amount' : 'full_no_amount'), 'params' => array_filter(['amount' => $amount($expected)])],
                    ['item' => 'provider_refund', 'value' => $actual > 0 ? 'amount' : 'none', 'params' => array_filter(['amount' => $actual > 0 ? $amount($actual) : null, 'provider' => $provider])],
                    $lastSync,
                    $completeness,
                ],
                'causes' => [...($actual > 0 ? ['refund_partial_at_provider'] : ['refund_marked_only', 'refund_failed_at_provider']), ...($incomplete ? ['provider_data_incomplete'] : [])],
                'steps' => ['check_refund_at_provider', $appCannotRefund ? 'refund_in_provider_dashboard' : 'send_refund_through_provider_if_missing', 'recheck'],
                'warning' => 'no_double_refund',
                'auto_close' => 'provider_refund_matches',
            ] + $common;
        }

        return ['scenario' => null, 'params' => $params] + $common;
    }

    /**
     * @return array<string, mixed>
     */
    private function browserCheck(Incident $incident): array
    {
        $evidence = $this->latestEvidence($incident, Signal::TYPE_BROWSER_CHECK);
        $runId = is_string($evidence['check_run_id'] ?? null) ? $evidence['check_run_id'] : null;
        /** @var CheckRun|null $run */
        $run = $runId === null ? null : CheckRun::query()->where('tenant_id', $incident->tenant_id)->whereKey($runId)->first();
        $attemptIds = $run === null ? collect() : DB::table('check_attempts')->where('tenant_id', $incident->tenant_id)->where('run_id', $run->id)->pluck('id');
        $reached = $attemptIds->isEmpty() ? null : DB::table('check_steps')->whereIn('attempt_id', $attemptIds)->where('status', 'passed')->orderByDesc('step_index')->value('step_code');
        $screenshot = $attemptIds->isNotEmpty() && DB::table('artifacts')->whereIn('attempt_id', $attemptIds)->where('kind', 'screenshot')->where('state', 'ready')->whereNull('deleted_at')->where('expires_at', '>', Carbon::now())->exists();
        $failed = is_string($evidence['failed_step'] ?? null) ? $evidence['failed_step'] : null;
        $error = is_string($evidence['error_code'] ?? null) ? $evidence['error_code'] : null;

        return [
            'scenario' => self::SCENARIO_BROWSER_CHECK_STOPPED,
            'variant' => $failed === null ? 'step_unknown' : 'step_known',
            'params' => array_filter(['step' => $failed, 'reached' => $reached, 'error' => $error, 'check_run_id' => $run?->id]),
            'evidence' => [
                ['item' => 'stopped_step', 'value' => $failed === null ? 'unknown' : 'step', 'params' => array_filter(['step' => $failed])],
                ['item' => 'reached_step', 'value' => $reached === null ? 'none' : 'step', 'params' => array_filter(['step' => $reached])],
                ['item' => 'stop_reason', 'value' => $error === null ? 'unknown' : 'error', 'params' => array_filter(['error' => $error])],
                ['item' => 'screenshot', 'value' => $screenshot ? 'available' : 'not_available', 'params' => []],
                ['item' => 'last_passed_check', 'value' => $incident->last_good_at === null ? 'never' : 'at', 'params' => array_filter(['at' => $incident->last_good_at?->toJSON()])],
            ],
            'causes' => ['step_failed_on_site', 'page_changed', 'temporary_outage'],
            'steps' => [...($run !== null ? ['open_check_run'] : []), 'repeat_path_manually', 'check_recent_changes', 'run_check_again'],
            'warning' => 'browser_check_no_payment',
            'auto_close' => 'scheduled_checks_pass',
            'observation' => null,
            'technical' => array_filter(['rule_code' => $incident->title_code, 'error_code' => $error, 'check_run_id' => $run?->id, 'source' => 'browser_check']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function source(Incident $incident): array
    {
        $evidence = $this->latestEvidence($incident, Signal::TYPE_CONNECTOR_FRESHNESS);
        $reason = is_string($evidence['reason'] ?? null) ? $evidence['reason'] : null;
        $provider = is_string($evidence['provider'] ?? null) ? $evidence['provider'] : null;
        /** @var Integration|null $integration */
        $integration = is_string($evidence['integration_id'] ?? null) ? Integration::query()->where('tenant_id', $incident->tenant_id)->whereKey($evidence['integration_id'])->first() : null;
        $lastHeartbeat = is_string($evidence['last_heartbeat_at'] ?? null) ? $evidence['last_heartbeat_at'] : null;
        $lastSync = $integration?->last_successful_sync_at?->toJSON();
        [$causes, $steps] = match ($reason) {
            'api_key_rejected' => [['key_revoked_or_changed'], ['replace_key', 'check_connection']],
            'api_sync_failing', 'api_not_synced_yet' => [['site_unreachable', 'key_permissions_changed'], ['check_site_reachable', 'check_key_permissions', 'check_connection']],
            'no_heartbeat_since_pairing' => [['plugin_not_active'], ['check_plugin_active', 'check_scheduler']],
            'delivery_backlog', 'local_queue_full' => [['scheduler_slow_or_stopped', 'site_overloaded'], ['check_scheduler', 'check_site_reachable']],
            default => [['site_down', 'plugin_disabled', 'scheduler_slow_or_stopped'], ['check_site_reachable', 'check_plugin_active', 'check_scheduler']],
        };

        return [
            'scenario' => self::SCENARIO_SOURCE_UNAVAILABLE,
            'variant' => $incident->title_code === ConnectorFreshness::TITLE_DELIVERY_DELAYED ? 'delayed' : 'stale',
            'params' => array_filter(['provider' => $provider, 'reason' => $reason]),
            'evidence' => [
                ['item' => 'source', 'value' => $provider === null ? 'unknown' : 'connector', 'params' => array_filter(['provider' => $provider])],
                ['item' => 'source_reason', 'value' => $reason === null ? 'unknown' : 'reason', 'params' => array_filter(['reason' => $reason])],
                ['item' => 'last_signal', 'value' => $lastHeartbeat === null ? 'never' : 'at', 'params' => array_filter(['at' => $lastHeartbeat])],
                ['item' => 'last_successful_sync', 'value' => $lastSync === null ? 'never' : 'at', 'params' => array_filter(['at' => $lastSync])],
            ],
            'causes' => $causes,
            'steps' => $steps,
            'warning' => 'source_gap_not_sales_stop',
            'auto_close' => 'source_fresh_again',
            'observation' => ['state' => $incident->title_code === ConnectorFreshness::TITLE_DELIVERY_DELAYED ? 'partial' : 'unavailable', 'reason' => $reason, 'checked_at' => $lastHeartbeat ?? $lastSync],
            'technical' => array_filter(['rule_code' => $incident->title_code, 'reason_code' => $reason, 'integration_id' => $integration?->id, 'source' => 'connector_freshness']),
        ];
    }

    /**
     * How far the money data can be trusted now: the provider connection that would hold the order's money and the
     * store's own connector.
     *
     * @return array{state: string, reason: ?string, checked_at: ?string}
     */
    private function providerObservation(Incident $incident, ?string $provider): array
    {
        /** @var Integration|null $integration */
        $integration = Integration::query()
            ->where('tenant_id', $incident->tenant_id)
            ->where('store_id', $incident->store_id)
            ->where('source_authority', Integration::SOURCE_INDEPENDENT_PROVIDER)
            ->whereIn('status', [Integration::STATUS_ACTIVE, Integration::STATUS_DEGRADED])
            ->when($provider !== null, fn ($query) => $query->where('provider', $provider))
            ->orderByRaw("CASE status WHEN 'active' THEN 0 ELSE 1 END")
            ->first();
        $checkedAt = $integration?->last_successful_sync_at;
        $health = is_array($integration?->health) ? $integration->health : [];
        $reason = match (true) {
            $integration === null => 'provider_not_connected',
            $integration->status === Integration::STATUS_DEGRADED || isset($health['key_rejected_at']) => 'provider_access_lost',
            $checkedAt === null => 'provider_not_synced_yet',
            $checkedAt->lessThan(Carbon::now()->subHours(self::PROVIDER_SYNC_STALE_HOURS)) => 'provider_sync_stale',
            ($health['sync']['complete'] ?? true) === false => 'provider_sync_incomplete',
            $this->freshness->storeDataStale($incident->tenant_id, $incident->store_id) => 'store_data_stale',
            default => null,
        };

        return [
            'state' => match ($reason) {
                null => 'fresh',
                'provider_not_connected', 'provider_access_lost' => 'unavailable',
                default => 'partial',
            },
            'reason' => $reason,
            'checked_at' => $checkedAt?->toJSON(),
        ];
    }

    /**
     * What is known about the problem, apart from how fresh the data is, and what the last check changed. A problem
     * found earlier that cannot be checked again now is neither fixed nor freshly confirmed. Closing by hand is not a
     * confirmation.
     *
     * @param  array{state: string, reason: ?string, checked_at: ?string}|null  $observation
     * @return array{status: string, outcome: ?array<string, mixed>}
     */
    private function state(Incident $incident, ?array $observation): array
    {
        if ($incident->state === Incident::STATE_RESOLVED) {
            $automatic = str_starts_with((string) $incident->resolution_reason, 'auto_resolved');

            return [
                'status' => $automatic ? 'resolved_confirmed' : 'closed_by_user',
                'outcome' => ['code' => $automatic ? 'confirmed_fixed' : 'closed_by_user', 'at' => $incident->resolved_at?->toJSON(), 'reason' => $automatic ? $incident->resolution_reason : null],
            ];
        }

        $checkedAt = $this->lastCheckAt($incident);

        return [
            'status' => ($observation['state'] ?? null) === 'unavailable' && $incident->family === 'money' ? 'unverifiable' : 'detected',
            'outcome' => $checkedAt !== null && $incident->first_seen_at !== null && $checkedAt->greaterThan($incident->first_seen_at->copy()->addMinute())
                ? ['code' => ($observation['state'] ?? null) === 'unavailable' && $incident->family === 'money' ? 'not_rechecked' : 'still_present', 'at' => $checkedAt->toJSON(), 'reason' => null]
                : null,
        ];
    }

    private function lastCheckAt(Incident $incident): ?Carbon
    {
        if ($incident->family === 'money') {
            $orderId = $this->latestFinding($incident)?->order_id;
            $at = $orderId === null ? null : DB::table('reconciliation_subject_states')->where('tenant_id', $incident->tenant_id)->where('subject_type', 'order')->where('subject_id', $orderId)->value('evaluated_at');

            return $at === null ? null : Carbon::parse($at);
        }

        return $incident->last_seen_at;
    }

    /**
     * @param  array<string, mixed>|null  $amount
     * @return array{item: string, value: string, params: array<string, mixed>}
     */
    private function storeOrderRow(?Order $order, ?array $amount): array
    {
        return ['item' => 'store_order', 'value' => $order === null ? 'unknown' : ($amount === null ? 'order_only' : 'order'), 'params' => array_filter(['order' => $order?->display_number, 'amount' => $amount])];
    }

    private function referencedPayment(Order $order): ?Payment
    {
        $reference = (string) ($order->transaction_ref ?? '');
        $storeTransaction = $order->metadata['store_transaction_ref'] ?? null;

        if ($reference === '' && ! is_string($storeTransaction)) {
            return null;
        }

        /** @var Payment|null $payment */
        $payment = Payment::query()
            ->where('tenant_id', $order->tenant_id)
            ->where('store_id', $order->store_id)
            ->where('source_authority', Integration::SOURCE_INDEPENDENT_PROVIDER)
            ->where(function ($query) use ($reference, $storeTransaction): void {
                if ($reference !== '') {
                    $query->orWhere('intent_ref', $reference)->orWhere('charge_ref', $reference);
                }

                if (is_string($storeTransaction)) {
                    $query->orWhere('metadata->provider_store_transaction_ref', $storeTransaction);
                }
            })
            ->orderByDesc('source_updated_at')
            ->first();

        return $payment;
    }

    private function latestFinding(Incident $incident): ?ReconciliationFinding
    {
        /** @var ReconciliationFinding|null $finding */
        $finding = ReconciliationFinding::query()
            ->where('tenant_id', $incident->tenant_id)
            ->whereIn('id', Signal::query()->whereIn('id', IncidentSignal::query()->where('incident_id', $incident->id)->select('signal_id'))->whereNotNull('finding_id')->select('finding_id'))
            ->orderByRaw("CASE status WHEN 'mismatch' THEN 0 ELSE 1 END")
            ->orderByDesc('evaluated_at')
            ->first();

        return $finding;
    }

    /**
     * @return array<string, mixed>
     */
    private function latestEvidence(Incident $incident, string $signalType): array
    {
        $evidence = Signal::query()
            ->where('tenant_id', $incident->tenant_id)
            ->where('signal_type', $signalType)
            ->whereIn('id', IncidentSignal::query()->where('incident_id', $incident->id)->select('signal_id'))
            ->where('severity', '!=', Signal::SEVERITY_INFO)
            ->orderByDesc('detected_at')
            ->value('evidence');

        if (is_string($evidence)) {
            $evidence = json_decode($evidence, true);
        }

        return is_array($evidence) ? $evidence : [];
    }
}
