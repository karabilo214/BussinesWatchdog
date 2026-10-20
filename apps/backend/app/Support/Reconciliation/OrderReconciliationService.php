<?php

namespace App\Support\Reconciliation;

use App\Models\FinancialTransaction;
use App\Models\Integration;
use App\Models\Order;
use App\Models\OrderRevision;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\ReconciliationFinding;
use App\Models\ReconciliationRun;
use App\Models\Refund;
use App\Models\RefundAllocation;
use App\Support\Billing\EntitlementCalculator;
use App\Support\Billing\FeatureCatalog;
use App\Support\Integrations\ConnectorFreshness;
use App\Support\Integrations\ProviderCoverage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class OrderReconciliationService
{
    public const ALGORITHM_VERSION = 'v3';

    public const CONFIG_VERSION = 1;

    public const TOLERANCE_MINOR = 0;

    public const GRACE_CAPTURE_MINUTES = 30;

    public const GRACE_REFUND_MINUTES = 60;

    public function __construct(
        private readonly ProviderCoverage $coverage,
        private readonly ConnectorFreshness $freshness,
        private readonly ReconciliationResultRecorder $recorder,
    ) {}

    public function evaluate(Order $order, string $trigger = 'manual'): ReconciliationRun
    {
        return DB::transaction(function () use ($order, $trigger): ReconciliationRun {
            /** @var Order $lockedOrder */
            $lockedOrder = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            $now = Carbon::now();
            $providerConnected = $this->coverage->isConnected($lockedOrder->tenant_id, $lockedOrder->store_id, $lockedOrder->gateway);
            $storeDataStale = $this->freshness->storeDataStale($lockedOrder->tenant_id, $lockedOrder->store_id);

            $runAttributes = [
                'tenant_id' => $lockedOrder->tenant_id,
                'store_id' => $lockedOrder->store_id,
                'algorithm_version' => self::ALGORITHM_VERSION,
                'config_version' => self::CONFIG_VERSION,
                'currency' => $lockedOrder->currency,
                'scope' => ['order_id' => $lockedOrder->id, 'trigger' => $trigger],
                'coverage_snapshot' => [
                    'financial_support' => $lockedOrder->financial_support,
                    'provider_connected' => $providerConnected,
                    'store_data_stale' => $storeDataStale,
                ],
                'counters' => [],
                'started_at' => $now,
                'created_at' => $now,
            ];
            $run = new ReconciliationRun($runAttributes);
            $entitled = app(EntitlementCalculator::class)->forTenant($lockedOrder->tenant_id)->allows(FeatureCatalog::MONEY_RECONCILIATION);

            $findings = [];

            if (! $entitled) {
                $findings[] = $this->findingAttributes($run, $lockedOrder, ReconciliationFinding::RULE_UNSUPPORTED, [
                    'status' => ReconciliationFinding::STATUS_UNKNOWN,
                    'reason_code' => 'not_entitled',
                    'evidence' => ['feature' => FeatureCatalog::MONEY_RECONCILIATION],
                ], $now);
            } elseif ($lockedOrder->financial_support !== 'supported') {
                $findings[] = $this->findingAttributes($run, $lockedOrder, ReconciliationFinding::RULE_UNSUPPORTED, [
                    'status' => ReconciliationFinding::STATUS_UNSUPPORTED,
                    'reason_code' => 'gateway_unsupported',
                    'evidence' => ['financial_support' => $lockedOrder->financial_support],
                ], $now);
            } elseif (! $providerConnected) {
                $findings[] = $this->findingAttributes($run, $lockedOrder, ReconciliationFinding::RULE_UNSUPPORTED, [
                    'status' => ReconciliationFinding::STATUS_UNKNOWN,
                    'reason_code' => ProviderCoverage::REASON_NOT_CONNECTED,
                    'evidence' => ['financial_support' => $lockedOrder->financial_support],
                ], $now);
            } elseif (($lockedOrder->metadata['mode_reported'] ?? true) === false && $this->coverage->onlyTestMode($lockedOrder->tenant_id, $lockedOrder->store_id, $lockedOrder->gateway)) {
                $findings[] = $this->findingAttributes($run, $lockedOrder, ReconciliationFinding::RULE_UNSUPPORTED, [
                    'status' => ReconciliationFinding::STATUS_UNKNOWN,
                    'reason_code' => ProviderCoverage::REASON_MODE_NEEDS_PLUGIN,
                    'evidence' => ['financial_support' => $lockedOrder->financial_support, 'order_mode_reported' => false],
                ], $now);
            } elseif ($storeDataStale) {
                $findings[] = $this->findingAttributes($run, $lockedOrder, ReconciliationFinding::RULE_UNSUPPORTED, [
                    'status' => ReconciliationFinding::STATUS_UNKNOWN,
                    'reason_code' => ConnectorFreshness::REASON_STORE_DATA_STALE,
                    'evidence' => ['financial_support' => $lockedOrder->financial_support],
                ], $now);
            } else {
                $allocations = $this->activeCaptureAllocations($lockedOrder);
                $otherCurrencyPayment = $this->referencedPaymentInOtherCurrency($lockedOrder);
                $baselineTotalMinor = $this->totalAtCapture($lockedOrder, $allocations);
                $refundFindings = $this->evaluateRefundRules($run, $lockedOrder, $allocations, $now);

                $findings = array_merge(
                    $findings,
                    $this->evaluateCaptureRules($run, $lockedOrder, $allocations, $otherCurrencyPayment, $baselineTotalMinor, $now),
                    $this->evaluateCaptureRecordedByStore($run, $lockedOrder, $allocations, $now),
                    $this->evaluateMultipleCaptures($run, $lockedOrder, $allocations, $now),
                    $this->evaluateCurrencyMismatch($run, $lockedOrder, $otherCurrencyPayment, $now),
                    $this->evaluateOrderChanged($run, $lockedOrder, $baselineTotalMinor, $now),
                    $this->evaluateCancelledPaid($run, $lockedOrder, $allocations, $now),
                    $refundFindings,
                );
            }

            return $this->recorder->record(ReconciliationResultRecorder::SUBJECT_ORDER, $lockedOrder->id, $runAttributes, $findings, ['entitled' => $entitled, 'store_data_stale' => $storeDataStale]);
        });
    }

    /**
     * @return Collection<int, PaymentAllocation>
     */
    private function activeCaptureAllocations(Order $order): Collection
    {
        return PaymentAllocation::query()
            ->where('order_id', $order->id)
            ->whereNull('revoked_at')
            ->lockForUpdate()
            ->get();
    }

    /**
     * MONEY_CAPTURE_MISSING needs an exact mapping (TZ §14): only when a provider payment for the order's reference is
     * known does a missing capture become a discrepancy with an amount. A payment in another currency is reported by
     * MONEY_CURRENCY_MISMATCH alone, and an order whose reference matches no provider payment is "not matched", without
     * an amount (ADR 0068).
     *
     * @param  Collection<int, PaymentAllocation>  $allocations
     * @return list<array<string, mixed>>
     */
    private function evaluateCaptureRules(ReconciliationRun $run, Order $order, Collection $allocations, ?Payment $otherCurrencyPayment, ?int $baselineTotalMinor, Carbon $now): array
    {
        $gross = $order->total_minor;
        $capturedMinor = (int) $allocations->sum('amount_minor');

        if ($order->paid_marked_at !== null && $gross > 0) {
            if ($capturedMinor === 0) {
                $graceDeadline = $order->paid_marked_at->copy()->addMinutes(self::GRACE_CAPTURE_MINUTES);

                if ($now->lessThan($graceDeadline)) {
                    return [$this->findingAttributes($run, $order, ReconciliationFinding::RULE_CAPTURE_MISSING, [
                        'status' => ReconciliationFinding::STATUS_PENDING,
                        'reason_code' => 'capture_missing_within_grace',
                        'gross_minor' => $gross,
                        'captured_minor' => 0,
                        'expected_minor' => $gross,
                        'actual_minor' => 0,
                        'difference_minor' => -$gross,
                        'evidence' => ['paid_marked_at' => $order->paid_marked_at->toJSON(), 'grace_deadline_at' => $graceDeadline->toJSON()],
                    ], $now)];
                }

                if ($otherCurrencyPayment !== null) {
                    return [$this->findingAttributes($run, $order, ReconciliationFinding::RULE_CAPTURE_MISSING, [
                        'status' => ReconciliationFinding::STATUS_UNKNOWN,
                        'reason_code' => 'currency_mismatch',
                        'gross_minor' => $gross,
                        'payment_id' => $otherCurrencyPayment->id,
                        'evidence' => ['paid_marked_at' => $order->paid_marked_at->toJSON(), 'payment_currency' => $otherCurrencyPayment->currency],
                    ], $now)];
                }

                if (! $this->providerPaymentKnown($order)) {
                    return [$this->findingAttributes($run, $order, ReconciliationFinding::RULE_PAYMENT_NOT_MATCHED, [
                        'status' => ReconciliationFinding::STATUS_MISMATCH,
                        'reason_code' => 'payment_not_matched',
                        'gross_minor' => $gross,
                        'evidence' => array_filter([
                            'paid_marked_at' => $order->paid_marked_at->toJSON(),
                            'transaction_ref_present' => ($order->transaction_ref ?? '') !== '',
                        ], fn (mixed $value): bool => $value !== null),
                    ], $now)];
                }

                return [$this->findingAttributes($run, $order, ReconciliationFinding::RULE_CAPTURE_MISSING, [
                    'status' => ReconciliationFinding::STATUS_MISMATCH,
                    'reason_code' => 'capture_missing_grace_expired',
                    'gross_minor' => $gross,
                    'captured_minor' => 0,
                    'expected_minor' => $gross,
                    'actual_minor' => 0,
                    'difference_minor' => -$gross,
                    'evidence' => ['paid_marked_at' => $order->paid_marked_at->toJSON()],
                ], $now)];
            }

            return [$this->captureAmountFinding($run, $order, $gross, $capturedMinor, $baselineTotalMinor, $now)];
        }

        if ($capturedMinor > 0) {
            return [$this->captureAmountFinding($run, $order, $gross, $capturedMinor, $baselineTotalMinor, $now)];
        }

        return [];
    }

    /**
     * A provider payment the matcher could link to this order: the order's reference names its intent, charge or capture,
     * or its metadata names the order. Only then is a missing capture a fact rather than a missing link.
     */
    private function providerPaymentKnown(Order $order): bool
    {
        $reference = (string) ($order->transaction_ref ?? '');

        $payments = Payment::query()
            ->where('tenant_id', $order->tenant_id)
            ->where('store_id', $order->store_id)
            ->where('source_authority', Integration::SOURCE_INDEPENDENT_PROVIDER)
            ->where(function ($query) use ($order, $reference): void {
                if ($reference !== '') {
                    $query->orWhere('intent_ref', $reference)->orWhere('charge_ref', $reference);
                }

                $query->orWhere('metadata->provider_order_ref', $order->external_id);
            });

        if ($payments->exists()) {
            return true;
        }

        return $reference !== '' && FinancialTransaction::query()
            ->where('tenant_id', $order->tenant_id)
            ->where('store_id', $order->store_id)
            ->where('source_authority', Integration::SOURCE_INDEPENDENT_PROVIDER)
            ->where('external_operation_id', $reference)
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    private function captureAmountFinding(ReconciliationRun $run, Order $order, int $gross, int $capturedMinor, ?int $baselineTotalMinor, Carbon $now): array
    {
        $difference = $capturedMinor - $gross;
        $isMismatch = abs($difference) > self::TOLERANCE_MINOR;
        $changedAfterCapture = $isMismatch && $baselineTotalMinor !== null;

        return $this->findingAttributes($run, $order, ReconciliationFinding::RULE_CAPTURE_AMOUNT, [
            'status' => $changedAfterCapture ? ReconciliationFinding::STATUS_UNKNOWN : ($isMismatch ? ReconciliationFinding::STATUS_MISMATCH : ReconciliationFinding::STATUS_OK),
            'reason_code' => $changedAfterCapture ? 'order_changed_after_capture' : ($isMismatch ? 'capture_amount_mismatch' : 'capture_amount_ok'),
            'gross_minor' => $gross,
            'captured_minor' => $capturedMinor,
            'expected_minor' => $gross,
            'actual_minor' => $capturedMinor,
            'difference_minor' => $difference,
            'evidence' => [],
        ], $now);
    }

    /**
     * A provider-confirmed capture must eventually be reflected as paid by the store.
     * The store's own payment marker is never used as capture evidence.
     *
     * @param  Collection<int, PaymentAllocation>  $allocations
     * @return list<array<string, mixed>>
     */
    private function evaluateCaptureRecordedByStore(ReconciliationRun $run, Order $order, Collection $allocations, Carbon $now): array
    {
        if ($allocations->isEmpty()) {
            return [];
        }

        $capturedMinor = (int) $allocations->sum('amount_minor');

        if ($capturedMinor <= 0) {
            return [];
        }

        $earliestCaptureAt = FinancialTransaction::query()
            ->where('tenant_id', $order->tenant_id)
            ->where('store_id', $order->store_id)
            ->whereIn('id', $allocations->pluck('capture_transaction_id')->unique())
            ->min('occurred_at');

        if ($earliestCaptureAt === null) {
            return [];
        }

        $graceDeadline = Carbon::parse($earliestCaptureAt)->addMinutes(self::GRACE_CAPTURE_MINUTES);
        $recorded = $order->paid_marked_at !== null;
        $pastGrace = $now->greaterThanOrEqualTo($graceDeadline);

        return [$this->findingAttributes($run, $order, ReconciliationFinding::RULE_CAPTURE_NOT_RECORDED, [
            'status' => $recorded ? ReconciliationFinding::STATUS_OK : ($pastGrace ? ReconciliationFinding::STATUS_MISMATCH : ReconciliationFinding::STATUS_PENDING),
            'reason_code' => $recorded ? 'capture_recorded_by_store' : ($pastGrace ? 'capture_not_recorded_grace_expired' : 'capture_not_recorded_within_grace'),
            'gross_minor' => $order->total_minor,
            'captured_minor' => $capturedMinor,
            'evidence' => $recorded || $pastGrace ? [] : ['grace_deadline_at' => $graceDeadline->toJSON()],
        ], $now)];
    }

    /**
     * @param  Collection<int, PaymentAllocation>  $allocations
     * @return list<array<string, mixed>>
     */
    private function evaluateMultipleCaptures(ReconciliationRun $run, Order $order, Collection $allocations, Carbon $now): array
    {
        $distinctCaptureTransactionIds = $allocations->pluck('capture_transaction_id')->unique();

        if ($distinctCaptureTransactionIds->count() <= 1) {
            return [];
        }

        return [$this->findingAttributes($run, $order, ReconciliationFinding::RULE_MULTIPLE_CAPTURES, [
            'status' => ReconciliationFinding::STATUS_MISMATCH,
            'reason_code' => 'multiple_distinct_captures',
            'evidence' => ['capture_transaction_ids' => $distinctCaptureTransactionIds->values()->all()],
        ], $now)];
    }

    private function referencedPaymentInOtherCurrency(Order $order): ?Payment
    {
        if ($order->transaction_ref === null || $order->transaction_ref === '') {
            return null;
        }

        /** @var Payment|null $candidate */
        $candidate = Payment::query()
            ->where('tenant_id', $order->tenant_id)
            ->where('store_id', $order->store_id)
            ->where(function ($query) use ($order) {
                $query->where('intent_ref', $order->transaction_ref)
                    ->orWhere('charge_ref', $order->transaction_ref);
            })
            ->first();

        return $candidate !== null && $candidate->currency !== $order->currency ? $candidate : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function evaluateCurrencyMismatch(ReconciliationRun $run, Order $order, ?Payment $candidate, Carbon $now): array
    {
        if ($candidate === null) {
            return [];
        }

        return [$this->findingAttributes($run, $order, ReconciliationFinding::RULE_CURRENCY_MISMATCH, [
            'status' => ReconciliationFinding::STATUS_MISMATCH,
            'reason_code' => 'currency_mismatch_detected',
            'payment_id' => $candidate->id,
            'evidence' => ['order_currency' => $order->currency, 'payment_currency' => $candidate->currency],
        ], $now)];
    }

    /**
     * The order total as the store reported it at the first allocated capture, when it differs from the current total;
     * null when there is no capture, no revision from before it, or the total did not change.
     *
     * @param  Collection<int, PaymentAllocation>  $allocations
     */
    private function totalAtCapture(Order $order, Collection $allocations): ?int
    {
        $captureTransactionIds = $allocations->pluck('capture_transaction_id')->unique();

        if ($captureTransactionIds->isEmpty()) {
            return null;
        }

        $earliestCaptureAt = FinancialTransaction::query()
            ->whereIn('id', $captureTransactionIds)
            ->min('occurred_at');

        if ($earliestCaptureAt === null) {
            return null;
        }

        /** @var OrderRevision|null $baselineRevision */
        $baselineRevision = OrderRevision::query()
            ->where('order_id', $order->id)
            ->where('observed_at', '<=', $earliestCaptureAt)
            ->orderByDesc('observed_at')
            ->orderByDesc('source_revision')
            ->first();

        if ($baselineRevision === null) {
            return null;
        }

        $baselineTotalMinor = (int) ($baselineRevision->snapshot['data']['total_minor'] ?? $order->total_minor);

        return $baselineTotalMinor === $order->total_minor ? null : $baselineTotalMinor;
    }

    /**
     * A total changed after capture needs review; it is never read as an underpayment on its own (TZ §12), so the
     * finding keeps both totals as facts but the incident carries no discrepancy amount (ADR 0068).
     *
     * @return list<array<string, mixed>>
     */
    private function evaluateOrderChanged(ReconciliationRun $run, Order $order, ?int $baselineTotalMinor, Carbon $now): array
    {
        if ($baselineTotalMinor === null) {
            return [];
        }

        return [$this->findingAttributes($run, $order, ReconciliationFinding::RULE_ORDER_CHANGED, [
            'status' => ReconciliationFinding::STATUS_MISMATCH,
            'reason_code' => 'order_changed_after_capture',
            'expected_minor' => $baselineTotalMinor,
            'actual_minor' => $order->total_minor,
            'difference_minor' => $order->total_minor - $baselineTotalMinor,
            'evidence' => [],
        ], $now)];
    }

    /**
     * A cancelled order the store marked paid, with a capture and no refund at all: the status alone does not return
     * money (TZ §12), so after the refund grace it asks for a review, without a discrepancy amount (ADR 0068).
     *
     * @param  Collection<int, PaymentAllocation>  $allocations
     * @return list<array<string, mixed>>
     */
    private function evaluateCancelledPaid(ReconciliationRun $run, Order $order, Collection $allocations, Carbon $now): array
    {
        $capturedMinor = (int) $allocations->sum('amount_minor');

        if ($order->paid_marked_at === null || $capturedMinor <= 0) {
            return [];
        }

        $hasRefund = Refund::query()->where('order_id', $order->id)->whereIn('status', ['requested', 'recorded'])->exists()
            || FinancialTransaction::query()
                ->whereIn('payment_id', $allocations->pluck('payment_id')->unique())
                ->where('kind', 'refund')
                ->whereIn('status', ['pending', 'succeeded'])
                ->exists();
        $cancelled = $order->status === 'cancelled' && ! $hasRefund;

        if (! $cancelled && ! ReconciliationFinding::query()->where('order_id', $order->id)->where('rule_code', ReconciliationFinding::RULE_CANCELLED_PAID)->exists()) {
            return [];
        }

        $graceDeadline = ($order->source_updated_at ?? $now)->copy()->addMinutes(self::GRACE_REFUND_MINUTES);
        $pending = $cancelled && $now->lessThan($graceDeadline);

        return [$this->findingAttributes($run, $order, ReconciliationFinding::RULE_CANCELLED_PAID, [
            'status' => ! $cancelled ? ReconciliationFinding::STATUS_OK : ($pending ? ReconciliationFinding::STATUS_PENDING : ReconciliationFinding::STATUS_MISMATCH),
            'reason_code' => ! $cancelled ? 'not_cancelled_or_refunded' : ($pending ? 'cancelled_paid_within_grace' : 'cancelled_paid_review'),
            'gross_minor' => $order->total_minor,
            'captured_minor' => $capturedMinor,
            'evidence' => $pending ? ['grace_deadline_at' => $graceDeadline->toJSON()] : [],
        ], $now)];
    }

    /**
     * RP also counts the part of a succeeded provider refund that no store refund claims, on a payment linked to this
     * order alone: a refund made directly at the provider is a disagreement too (FIN-08, ADR 0068). A payment shared
     * by several orders leaves such a refund for a person to link.
     *
     * @param  Collection<int, PaymentAllocation>  $allocations
     * @return list<array<string, mixed>>
     */
    private function evaluateRefundRules(ReconciliationRun $run, Order $order, Collection $allocations, Carbon $now): array
    {
        $refundState = $order->metadata['refund_state'] ?? null;

        if (in_array($refundState, ['none', 'partial', 'full'], true)) {
            return $this->evaluateStateRefunds($run, $order, $refundState, $now);
        }

        $outstandingRefunds = Refund::query()
            ->where('order_id', $order->id)
            ->where('external_required', true)
            ->whereIn('status', ['requested', 'recorded'])
            ->lockForUpdate()
            ->get();

        $refundExpectedMinor = (int) $outstandingRefunds->sum('amount_minor');
        $latestRefundOccurredAt = $outstandingRefunds->max('occurred_at');

        $activeRefundAllocations = RefundAllocation::query()
            ->whereNull('revoked_at')
            ->whereHas('refund', fn ($query) => $query->where('order_id', $order->id))
            ->lockForUpdate()
            ->get();

        [$unclaimedMinor, $latestUnclaimedAt] = $this->unclaimedProviderRefunds($order, $allocations);
        $refundActualMinor = (int) $activeRefundAllocations->sum('amount_minor') + $unclaimedMinor;
        $latestRefundAllocationCreatedAt = collect([$activeRefundAllocations->max('created_at'), $latestUnclaimedAt])->filter()->max();

        $unknownRefunds = Refund::query()
            ->where('order_id', $order->id)
            ->whereNull('external_required')
            ->whereIn('status', ['requested', 'recorded'])
            ->count();

        if ($unknownRefunds > 0) {
            return [$this->findingAttributes($run, $order, ReconciliationFinding::RULE_REFUND_MISSING, [
                'status' => ReconciliationFinding::STATUS_UNKNOWN,
                'reason_code' => 'refund_external_unknown',
                'refund_expected_minor' => $refundExpectedMinor,
                'refund_actual_minor' => $refundActualMinor,
                'evidence' => ['refunds_with_unknown_sending' => $unknownRefunds],
            ], $now)];
        }

        if ($refundExpectedMinor === 0 && $refundActualMinor === 0) {
            return [];
        }

        $difference = $refundActualMinor - $refundExpectedMinor;

        if ($difference === 0) {
            return [$this->findingAttributes($run, $order, ReconciliationFinding::RULE_REFUND_MISSING, [
                'status' => ReconciliationFinding::STATUS_OK,
                'reason_code' => 'refund_reconciled_ok',
                'refund_expected_minor' => $refundExpectedMinor,
                'refund_actual_minor' => $refundActualMinor,
                'expected_minor' => $refundExpectedMinor,
                'actual_minor' => $refundActualMinor,
                'difference_minor' => 0,
                'evidence' => [],
            ], $now)];
        }

        if ($difference < 0) {
            $graceDeadline = $latestRefundOccurredAt !== null
                ? $latestRefundOccurredAt->copy()->addMinutes(self::GRACE_REFUND_MINUTES)
                : $now;
            $isPastGrace = $now->greaterThanOrEqualTo($graceDeadline);

            return [$this->findingAttributes($run, $order, ReconciliationFinding::RULE_REFUND_MISSING, [
                'status' => $isPastGrace ? ReconciliationFinding::STATUS_MISMATCH : ReconciliationFinding::STATUS_PENDING,
                'reason_code' => $isPastGrace ? 'refund_missing_grace_expired' : 'refund_missing_within_grace',
                'refund_expected_minor' => $refundExpectedMinor,
                'refund_actual_minor' => $refundActualMinor,
                'expected_minor' => $refundExpectedMinor,
                'actual_minor' => $refundActualMinor,
                'difference_minor' => $difference,
                'evidence' => $isPastGrace ? [] : ['grace_deadline_at' => $graceDeadline->toJSON()],
            ], $now)];
        }

        $graceDeadline = $latestRefundAllocationCreatedAt !== null
            ? $latestRefundAllocationCreatedAt->copy()->addMinutes(self::GRACE_REFUND_MINUTES)
            : $now;
        $isPastGrace = $now->greaterThanOrEqualTo($graceDeadline);

        return [$this->findingAttributes($run, $order, ReconciliationFinding::RULE_REFUND_EXTRA, [
            'status' => $isPastGrace ? ReconciliationFinding::STATUS_MISMATCH : ReconciliationFinding::STATUS_PENDING,
            'reason_code' => $isPastGrace ? 'refund_extra_grace_expired' : 'refund_extra_within_grace',
            'refund_expected_minor' => $refundExpectedMinor,
            'refund_actual_minor' => $refundActualMinor,
            'expected_minor' => $refundExpectedMinor,
            'actual_minor' => $refundActualMinor,
            'difference_minor' => $difference,
            'evidence' => $isPastGrace ? [] : ['grace_deadline_at' => $graceDeadline->toJSON()],
        ], $now)];
    }

    /**
     * @param  Collection<int, PaymentAllocation>  $allocations
     * @return array{int, ?Carbon}
     */
    private function unclaimedProviderRefunds(Order $order, Collection $allocations): array
    {
        $paymentIds = $allocations->pluck('payment_id')->unique()->values();
        $exclusive = $paymentIds->filter(fn (string $paymentId): bool => ! PaymentAllocation::query()
            ->where('payment_id', $paymentId)
            ->whereNull('revoked_at')
            ->where('order_id', '!=', $order->id)
            ->exists());

        if ($exclusive->isEmpty()) {
            return [0, null];
        }

        $refunds = FinancialTransaction::query()
            ->whereIn('payment_id', $exclusive)
            ->where('kind', 'refund')
            ->where('status', 'succeeded')
            ->where('source_authority', Integration::SOURCE_INDEPENDENT_PROVIDER)
            ->where('currency', $order->currency)
            ->get(['id', 'amount_minor', 'occurred_at']);
        $claimed = RefundAllocation::query()
            ->whereIn('refund_transaction_id', $refunds->pluck('id'))
            ->whereNull('revoked_at')
            ->selectRaw('refund_transaction_id, SUM(amount_minor) AS claimed')
            ->groupBy('refund_transaction_id')
            ->pluck('claimed', 'refund_transaction_id');
        $unclaimed = 0;
        $latest = null;

        foreach ($refunds as $refund) {
            $rest = (int) $refund->amount_minor - (int) ($claimed[$refund->id] ?? 0);

            if ($rest > 0) {
                $unclaimed += $rest;
                $latest = $latest === null || $refund->occurred_at->greaterThan($latest) ? $refund->occurred_at : $latest;
            }
        }

        return [$unclaimed, $latest];
    }

    /**
     * Stores that keep a refund only as the payment state (ADR 0026): `full` must match the provider refunding the
     * whole captured amount, `partial` has no store amount (the provider's refunded amount is shown and the result is
     * unknown unless the provider refunded nothing or everything), `none` with provider refunds is an extra refund.
     *
     * @return list<array<string, mixed>>
     */
    private function evaluateStateRefunds(ReconciliationRun $run, Order $order, string $refundState, Carbon $now): array
    {
        $captureIds = PaymentAllocation::query()
            ->where('order_id', $order->id)
            ->whereNull('revoked_at')
            ->lockForUpdate()
            ->get(['capture_transaction_id', 'amount_minor']);
        $captured = (int) $captureIds->sum('amount_minor');
        $paymentIds = FinancialTransaction::query()->whereIn('id', $captureIds->pluck('capture_transaction_id'))->pluck('payment_id')->filter()->unique()->values();
        $refunds = $paymentIds->isEmpty() ? collect() : FinancialTransaction::query()
            ->whereIn('payment_id', $paymentIds)
            ->where('kind', 'refund')
            ->where('status', 'succeeded')
            ->where('currency', $order->currency)
            ->get(['amount_minor', 'occurred_at']);
        $refunded = (int) $refunds->sum('amount_minor');

        if ($refundState === 'none' && $refunded === 0 || $refundState !== 'none' && $captured === 0) {
            return [];
        }

        $missingGrace = ($order->source_updated_at ?? $now)->copy()->addMinutes(self::GRACE_REFUND_MINUTES);
        $extraGrace = ($refunds->max('occurred_at') ?? $now)->copy()->addMinutes(self::GRACE_REFUND_MINUTES);
        $evidence = ['refund_state' => $refundState, 'captured_minor' => (string) $captured, 'provider_refunded_minor' => (string) $refunded];

        [$rule, $status, $reason, $expected, $grace] = match (true) {
            $refundState === 'none' => [ReconciliationFinding::RULE_REFUND_EXTRA, ReconciliationFinding::STATUS_MISMATCH, 'refund_extra_store_state_none', 0, $extraGrace],
            $refundState === 'full' && $refunded === $captured => [ReconciliationFinding::RULE_REFUND_MISSING, ReconciliationFinding::STATUS_OK, 'refund_reconciled_ok', $captured, null],
            $refundState === 'full' && $refunded < $captured => [ReconciliationFinding::RULE_REFUND_MISSING, ReconciliationFinding::STATUS_MISMATCH, 'refund_missing_store_state_full', $captured, $missingGrace],
            $refundState === 'full' => [ReconciliationFinding::RULE_REFUND_EXTRA, ReconciliationFinding::STATUS_MISMATCH, 'refund_extra_grace_expired', $captured, $extraGrace],
            $refunded === 0 => [ReconciliationFinding::RULE_REFUND_MISSING, ReconciliationFinding::STATUS_MISMATCH, 'refund_missing_store_state_partial', null, $missingGrace],
            $refunded >= $captured => [ReconciliationFinding::RULE_REFUND_EXTRA, ReconciliationFinding::STATUS_MISMATCH, 'refund_partial_but_provider_full', null, $extraGrace],
            default => [ReconciliationFinding::RULE_REFUND_MISSING, ReconciliationFinding::STATUS_UNKNOWN, 'refund_amount_not_reported', null, null],
        };

        if ($status === ReconciliationFinding::STATUS_MISMATCH && $grace !== null && $now->lessThan($grace)) {
            $status = ReconciliationFinding::STATUS_PENDING;
            $evidence['grace_deadline_at'] = $grace->toJSON();
        }

        return [$this->findingAttributes($run, $order, $rule, [
            'status' => $status,
            'reason_code' => $reason,
            'refund_expected_minor' => $expected,
            'refund_actual_minor' => $refunded,
            'expected_minor' => $expected,
            'actual_minor' => $refunded,
            'difference_minor' => $expected === null ? null : $refunded - $expected,
            'evidence' => $evidence,
        ], $now)];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function findingAttributes(ReconciliationRun $run, Order $order, string $ruleCode, array $attributes, Carbon $now): array
    {
        return array_merge([
            'tenant_id' => $order->tenant_id,
            'store_id' => $order->store_id,
            'run_id' => $run->id,
            'order_id' => $order->id,
            'payment_id' => null,
            'rule_code' => $ruleCode,
            'currency' => $order->currency,
            'mode' => ($order->metadata['mode_reported'] ?? true) === false ? null : $order->mode,
            'currency_exponent' => $order->currency_exponent,
            'gross_minor' => null,
            'captured_minor' => null,
            'refund_expected_minor' => null,
            'refund_actual_minor' => null,
            'config_snapshot' => [
                'algorithm_version' => self::ALGORITHM_VERSION,
                'config_version' => self::CONFIG_VERSION,
                'tolerance_minor' => self::TOLERANCE_MINOR,
                'grace_capture_minutes' => self::GRACE_CAPTURE_MINUTES,
                'grace_refund_minutes' => self::GRACE_REFUND_MINUTES,
            ],
            'evaluated_at' => $now,
        ], $attributes);
    }
}
