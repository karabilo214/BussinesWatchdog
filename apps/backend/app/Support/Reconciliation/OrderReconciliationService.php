<?php

namespace App\Support\Reconciliation;

use App\Models\FinancialTransaction;
use App\Models\Order;
use App\Models\OrderRevision;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\ReconciliationFinding;
use App\Models\ReconciliationRun;
use App\Models\Refund;
use App\Models\RefundAllocation;
use App\Support\Integrations\ConnectorFreshness;
use App\Support\Integrations\ProviderCoverage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class OrderReconciliationService
{
    public const ALGORITHM_VERSION = 'v1';

    public const CONFIG_VERSION = 1;

    public const TOLERANCE_MINOR = 0;

    public const GRACE_CAPTURE_MINUTES = 30;

    public const GRACE_REFUND_MINUTES = 60;

    public function __construct(
        private readonly ProviderCoverage $coverage,
        private readonly ConnectorFreshness $freshness,
    ) {}

    public function evaluate(Order $order, string $trigger = 'manual'): ReconciliationRun
    {
        return DB::transaction(function () use ($order, $trigger): ReconciliationRun {
            /** @var Order $lockedOrder */
            $lockedOrder = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            $now = Carbon::now();
            $providerConnected = $this->coverage->isConnected($lockedOrder->tenant_id, $lockedOrder->store_id, $lockedOrder->gateway);
            $storeDataStale = $this->freshness->storeDataStale($lockedOrder->tenant_id, $lockedOrder->store_id);

            $run = ReconciliationRun::query()->create([
                'tenant_id' => $lockedOrder->tenant_id,
                'store_id' => $lockedOrder->store_id,
                'status' => ReconciliationRun::STATUS_RUNNING,
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
            ]);

            $findings = [];

            if ($lockedOrder->financial_support !== 'supported') {
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
            } elseif ($storeDataStale) {
                $findings[] = $this->findingAttributes($run, $lockedOrder, ReconciliationFinding::RULE_UNSUPPORTED, [
                    'status' => ReconciliationFinding::STATUS_UNKNOWN,
                    'reason_code' => ConnectorFreshness::REASON_STORE_DATA_STALE,
                    'evidence' => ['financial_support' => $lockedOrder->financial_support],
                ], $now);
            } else {
                $allocations = $this->activeCaptureAllocations($lockedOrder);

                $findings = array_merge(
                    $findings,
                    $this->evaluateCaptureRules($run, $lockedOrder, $allocations, $now),
                    $this->evaluateMultipleCaptures($run, $lockedOrder, $allocations, $now),
                    $this->evaluateCurrencyMismatch($run, $lockedOrder, $now),
                    $this->evaluateOrderChanged($run, $lockedOrder, $allocations, $now),
                    $this->evaluateRefundRules($run, $lockedOrder, $now),
                );
            }

            foreach ($findings as $finding) {
                ReconciliationFinding::query()->create($finding);
            }

            $run->forceFill([
                'status' => ReconciliationRun::STATUS_COMPLETED,
                'finished_at' => Carbon::now(),
                'counters' => ['findings' => count($findings)],
            ])->save();

            return $run->refresh();
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
     * @param  Collection<int, PaymentAllocation>  $allocations
     * @return list<array<string, mixed>>
     */
    private function evaluateCaptureRules(ReconciliationRun $run, Order $order, Collection $allocations, Carbon $now): array
    {
        $gross = $order->total_minor;
        $capturedMinor = (int) $allocations->sum('amount_minor');

        if ($order->paid_marked_at !== null && $gross > 0) {
            if ($capturedMinor === 0) {
                $graceDeadline = $order->paid_marked_at->copy()->addMinutes(self::GRACE_CAPTURE_MINUTES);
                $isPastGrace = $now->greaterThanOrEqualTo($graceDeadline);

                return [$this->findingAttributes($run, $order, ReconciliationFinding::RULE_CAPTURE_MISSING, [
                    'status' => $isPastGrace ? ReconciliationFinding::STATUS_MISMATCH : ReconciliationFinding::STATUS_PENDING,
                    'reason_code' => $isPastGrace ? 'capture_missing_grace_expired' : 'capture_missing_within_grace',
                    'gross_minor' => $gross,
                    'captured_minor' => 0,
                    'expected_minor' => $gross,
                    'actual_minor' => 0,
                    'difference_minor' => -$gross,
                    'evidence' => array_merge(
                        ['paid_marked_at' => $order->paid_marked_at->toJSON()],
                        $isPastGrace ? [] : ['grace_deadline_at' => $graceDeadline->toJSON()],
                    ),
                ], $now)];
            }

            return [$this->captureAmountFinding($run, $order, $gross, $capturedMinor, $now)];
        }

        if ($capturedMinor > 0) {
            return [$this->captureAmountFinding($run, $order, $gross, $capturedMinor, $now)];
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function captureAmountFinding(ReconciliationRun $run, Order $order, int $gross, int $capturedMinor, Carbon $now): array
    {
        $difference = $capturedMinor - $gross;
        $isMismatch = abs($difference) > self::TOLERANCE_MINOR;

        return $this->findingAttributes($run, $order, ReconciliationFinding::RULE_CAPTURE_AMOUNT, [
            'status' => $isMismatch ? ReconciliationFinding::STATUS_MISMATCH : ReconciliationFinding::STATUS_OK,
            'reason_code' => $isMismatch ? 'capture_amount_mismatch' : 'capture_amount_ok',
            'gross_minor' => $gross,
            'captured_minor' => $capturedMinor,
            'expected_minor' => $gross,
            'actual_minor' => $capturedMinor,
            'difference_minor' => $difference,
            'evidence' => [],
        ], $now);
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

    /**
     * @return list<array<string, mixed>>
     */
    private function evaluateCurrencyMismatch(ReconciliationRun $run, Order $order, Carbon $now): array
    {
        if ($order->transaction_ref === null) {
            return [];
        }

        $candidate = Payment::query()
            ->where('tenant_id', $order->tenant_id)
            ->where('store_id', $order->store_id)
            ->where(function ($query) use ($order) {
                $query->where('intent_ref', $order->transaction_ref)
                    ->orWhere('charge_ref', $order->transaction_ref);
            })
            ->first();

        if ($candidate === null || $candidate->currency === $order->currency) {
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
     * @param  Collection<int, PaymentAllocation>  $allocations
     * @return list<array<string, mixed>>
     */
    private function evaluateOrderChanged(ReconciliationRun $run, Order $order, Collection $allocations, Carbon $now): array
    {
        $captureTransactionIds = $allocations->pluck('capture_transaction_id')->unique();

        if ($captureTransactionIds->isEmpty()) {
            return [];
        }

        $earliestCaptureAt = FinancialTransaction::query()
            ->whereIn('id', $captureTransactionIds)
            ->min('occurred_at');

        if ($earliestCaptureAt === null) {
            return [];
        }

        /** @var OrderRevision|null $baselineRevision */
        $baselineRevision = OrderRevision::query()
            ->where('order_id', $order->id)
            ->where('observed_at', '<=', $earliestCaptureAt)
            ->orderByDesc('observed_at')
            ->orderByDesc('source_revision')
            ->first();

        if ($baselineRevision === null) {
            return [];
        }

        $baselineTotalMinor = (int) ($baselineRevision->snapshot['data']['total_minor'] ?? $order->total_minor);

        if ($baselineTotalMinor === $order->total_minor) {
            return [];
        }

        return [$this->findingAttributes($run, $order, ReconciliationFinding::RULE_ORDER_CHANGED, [
            'status' => ReconciliationFinding::STATUS_MISMATCH,
            'reason_code' => 'order_changed_after_capture',
            'expected_minor' => $baselineTotalMinor,
            'actual_minor' => $order->total_minor,
            'difference_minor' => $order->total_minor - $baselineTotalMinor,
            'evidence' => ['capture_occurred_at' => (string) $earliestCaptureAt],
        ], $now)];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function evaluateRefundRules(ReconciliationRun $run, Order $order, Carbon $now): array
    {
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

        $refundActualMinor = (int) $activeRefundAllocations->sum('amount_minor');
        $latestRefundAllocationCreatedAt = $activeRefundAllocations->max('created_at');

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
