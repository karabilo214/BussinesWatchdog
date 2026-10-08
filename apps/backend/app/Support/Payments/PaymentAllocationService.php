<?php

namespace App\Support\Payments;

use App\Exceptions\Payments\AllocationRejected;
use App\Models\AuditLog;
use App\Models\FinancialTransaction;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Refund;
use App\Models\RefundAllocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentAllocationService
{
    public const ERROR_SCOPE_MISMATCH = 'allocation_scope_mismatch';

    public const ERROR_CAPTURE_INVALID = 'allocation_capture_invalid';

    public const ERROR_REFUND_INVALID = 'allocation_refund_invalid';

    public const ERROR_CURRENCY_MISMATCH = 'allocation_currency_mismatch';

    public const ERROR_AMOUNT_EXCEEDS_CAPTURE = 'allocation_amount_exceeds_capture';

    public const ERROR_AMOUNT_EXCEEDS_REFUND = 'allocation_amount_exceeds_refund';

    public const ERROR_ALREADY_REVOKED = 'allocation_already_revoked';

    public const ERROR_REASON_REQUIRED = 'allocation_revoke_reason_required';

    public const ERROR_HAS_ACTIVE_REFUND_ALLOCATIONS = 'allocation_has_active_refund_allocations';

    /**
     * @param  array<string, mixed>  $evidence
     */
    public function allocateCapture(
        Payment $payment,
        FinancialTransaction $capture,
        Order $order,
        int $amountMinor,
        string $strategy,
        array $evidence,
        ?string $createdBy = null,
    ): PaymentAllocation {
        return DB::transaction(function () use ($payment, $capture, $order, $amountMinor, $strategy, $evidence, $createdBy): PaymentAllocation {
            /** @var Payment $lockedPayment */
            $lockedPayment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            /** @var FinancialTransaction $lockedCapture */
            $lockedCapture = FinancialTransaction::query()->whereKey($capture->id)->lockForUpdate()->firstOrFail();
            /** @var Order $lockedOrder */
            $lockedOrder = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            $this->assertSameScope($lockedPayment, $lockedCapture, $lockedOrder);

            if ($lockedCapture->payment_id !== $lockedPayment->id
                || $lockedCapture->kind !== 'capture'
                || $lockedCapture->status !== 'succeeded') {
                throw new AllocationRejected(self::ERROR_CAPTURE_INVALID);
            }

            if ($lockedPayment->currency !== $lockedCapture->currency || $lockedOrder->currency !== $lockedCapture->currency) {
                throw new AllocationRejected(self::ERROR_CURRENCY_MISMATCH);
            }

            $allocatedMinor = (int) PaymentAllocation::query()
                ->where('capture_transaction_id', $lockedCapture->id)
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->get()
                ->sum('amount_minor');

            if ($amountMinor < 0 || $allocatedMinor + $amountMinor > $lockedCapture->amount_minor) {
                throw new AllocationRejected(self::ERROR_AMOUNT_EXCEEDS_CAPTURE);
            }

            return PaymentAllocation::query()->create([
                'tenant_id' => $lockedPayment->tenant_id,
                'store_id' => $lockedPayment->store_id,
                'payment_id' => $lockedPayment->id,
                'capture_transaction_id' => $lockedCapture->id,
                'order_id' => $lockedOrder->id,
                'currency' => $lockedCapture->currency,
                'amount_minor' => $amountMinor,
                'strategy' => $strategy,
                'evidence' => $evidence,
                'created_by' => $createdBy,
                'created_at' => now(),
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    public function allocateRefund(
        Refund $refund,
        FinancialTransaction $refundTransaction,
        PaymentAllocation $paymentAllocation,
        int $amountMinor,
        string $strategy,
        array $evidence,
        ?string $createdBy = null,
    ): RefundAllocation {
        return DB::transaction(function () use ($refund, $refundTransaction, $paymentAllocation, $amountMinor, $strategy, $evidence, $createdBy): RefundAllocation {
            /** @var Refund $lockedRefund */
            $lockedRefund = Refund::query()->whereKey($refund->id)->lockForUpdate()->firstOrFail();
            /** @var FinancialTransaction $lockedRefundTransaction */
            $lockedRefundTransaction = FinancialTransaction::query()->whereKey($refundTransaction->id)->lockForUpdate()->firstOrFail();
            /** @var PaymentAllocation $lockedPaymentAllocation */
            $lockedPaymentAllocation = PaymentAllocation::query()->whereKey($paymentAllocation->id)->lockForUpdate()->firstOrFail();

            $this->assertSameScope($lockedRefund, $lockedRefundTransaction, $lockedPaymentAllocation);

            if ($lockedRefundTransaction->kind !== 'refund' || $lockedRefundTransaction->status !== 'succeeded') {
                throw new AllocationRejected(self::ERROR_REFUND_INVALID);
            }

            if ($lockedRefund->currency !== $lockedRefundTransaction->currency || $lockedPaymentAllocation->currency !== $lockedRefundTransaction->currency) {
                throw new AllocationRejected(self::ERROR_CURRENCY_MISMATCH);
            }

            $allocatedMinor = (int) RefundAllocation::query()
                ->where('refund_transaction_id', $lockedRefundTransaction->id)
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->get()
                ->sum('amount_minor');

            if ($amountMinor < 0 || $allocatedMinor + $amountMinor > $lockedRefundTransaction->amount_minor) {
                throw new AllocationRejected(self::ERROR_AMOUNT_EXCEEDS_REFUND);
            }

            return RefundAllocation::query()->create([
                'tenant_id' => $lockedRefund->tenant_id,
                'store_id' => $lockedRefund->store_id,
                'refund_id' => $lockedRefund->id,
                'refund_transaction_id' => $lockedRefundTransaction->id,
                'payment_allocation_id' => $lockedPaymentAllocation->id,
                'amount_minor' => $amountMinor,
                'currency' => $lockedRefundTransaction->currency,
                'strategy' => $strategy,
                'evidence' => $evidence,
                'created_by' => $createdBy,
                'created_at' => now(),
            ]);
        });
    }

    public function revokeCaptureAllocation(
        PaymentAllocation $allocation,
        string $reason,
        ?string $actorUserId = null,
        ?string $requestId = null,
    ): PaymentAllocation {
        return DB::transaction(function () use ($allocation, $reason, $actorUserId, $requestId): PaymentAllocation {
            $this->assertReasonPresent($reason);

            /** @var PaymentAllocation $locked */
            $locked = PaymentAllocation::query()->whereKey($allocation->id)->lockForUpdate()->firstOrFail();

            if ($locked->revoked_at !== null) {
                throw new AllocationRejected(self::ERROR_ALREADY_REVOKED);
            }

            $hasActiveRefundAllocations = RefundAllocation::query()
                ->where('payment_allocation_id', $locked->id)
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->get()
                ->isNotEmpty();

            if ($hasActiveRefundAllocations) {
                throw new AllocationRejected(self::ERROR_HAS_ACTIVE_REFUND_ALLOCATIONS);
            }

            $revokedAt = now();
            $locked->forceFill(['revoked_at' => $revokedAt])->save();

            AuditLog::query()->create([
                'tenant_id' => $locked->tenant_id,
                'store_id' => $locked->store_id,
                'actor_user_id' => $actorUserId,
                'actor_type' => AuditLog::ACTOR_USER,
                'action' => AuditLog::ACTION_PAYMENT_ALLOCATION_REVOKED,
                'entity_type' => AuditLog::ENTITY_PAYMENT_ALLOCATION,
                'entity_id' => $locked->id,
                'changes' => [
                    'revoked_at' => $revokedAt->toJSON(),
                    'reason' => $reason,
                ],
                'request_id' => $requestId ?? (string) Str::uuid(),
                'created_at' => $revokedAt,
            ]);

            return $locked->refresh();
        });
    }

    public function revokeRefundAllocation(
        RefundAllocation $allocation,
        string $reason,
        ?string $actorUserId = null,
        ?string $requestId = null,
    ): RefundAllocation {
        return DB::transaction(function () use ($allocation, $reason, $actorUserId, $requestId): RefundAllocation {
            $this->assertReasonPresent($reason);

            /** @var RefundAllocation $locked */
            $locked = RefundAllocation::query()->whereKey($allocation->id)->lockForUpdate()->firstOrFail();

            if ($locked->revoked_at !== null) {
                throw new AllocationRejected(self::ERROR_ALREADY_REVOKED);
            }

            $revokedAt = now();
            $locked->forceFill(['revoked_at' => $revokedAt])->save();

            AuditLog::query()->create([
                'tenant_id' => $locked->tenant_id,
                'store_id' => $locked->store_id,
                'actor_user_id' => $actorUserId,
                'actor_type' => AuditLog::ACTOR_USER,
                'action' => AuditLog::ACTION_REFUND_ALLOCATION_REVOKED,
                'entity_type' => AuditLog::ENTITY_REFUND_ALLOCATION,
                'entity_id' => $locked->id,
                'changes' => [
                    'revoked_at' => $revokedAt->toJSON(),
                    'reason' => $reason,
                ],
                'request_id' => $requestId ?? (string) Str::uuid(),
                'created_at' => $revokedAt,
            ]);

            return $locked->refresh();
        });
    }

    private function assertReasonPresent(string $reason): void
    {
        if (trim($reason) === '') {
            throw new AllocationRejected(self::ERROR_REASON_REQUIRED);
        }
    }

    private function assertSameScope(object ...$models): void
    {
        $tenantId = $models[0]->tenant_id;
        $storeId = $models[0]->store_id;

        foreach ($models as $model) {
            if ($model->tenant_id !== $tenantId || $model->store_id !== $storeId) {
                throw new AllocationRejected(self::ERROR_SCOPE_MISMATCH);
            }
        }
    }
}
