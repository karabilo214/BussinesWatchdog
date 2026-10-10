<?php

namespace App\Support\Payments;

use App\Exceptions\Payments\AllocationRejected;
use App\Models\EventInbox;
use App\Models\FinancialTransaction;
use App\Models\Integration;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Refund;
use App\Models\RefundAllocation;

/**
 * Spec §13, first rule: the exact gateway reference with account and mode. A capture is linked to an order only
 * when exactly one order of the store names the payment intent or charge as its transaction reference (same mode
 * and currency); a provider refund is linked to the store refund that names it. Ambiguity links nothing — the
 * payment stays in the unmatched list for a person to decide. Amount and time never link anything here.
 */
class ExactReferenceMatcher
{
    public const MATCHER_VERSION = 'exact_reference_v1';

    public function __construct(
        private readonly PaymentAllocationService $allocations,
    ) {}

    public function matchForEvent(EventInbox $event): void
    {
        match ($event->event_type) {
            EventInbox::EVENT_TRANSACTION_OBSERVED => $this->matchTransaction($event),
            EventInbox::EVENT_PAYMENT_SNAPSHOT => $this->matchPayment($event),
            EventInbox::EVENT_REFUND_SNAPSHOT => $this->matchStoreRefund($event),
            default => null,
        };
    }

    private function matchTransaction(EventInbox $event): void
    {
        /** @var FinancialTransaction|null $transaction */
        $transaction = FinancialTransaction::query()
            ->where('tenant_id', $event->tenant_id)
            ->where('integration_id', $event->integration_id)
            ->where('external_operation_id', $event->payload['data']['external_operation_id'] ?? null)
            ->first();

        if ($transaction === null) {
            return;
        }

        $transaction->kind === 'capture' ? $this->matchCapture($transaction) : $this->matchProviderRefund($transaction);
    }

    private function matchPayment(EventInbox $event): void
    {
        $paymentId = Payment::query()
            ->where('tenant_id', $event->tenant_id)
            ->where('integration_id', $event->integration_id)
            ->where('external_id', $event->aggregate_external_id)
            ->value('id');

        FinancialTransaction::query()
            ->where('tenant_id', $event->tenant_id)
            ->where('payment_id', $paymentId)
            ->get()
            ->each(fn (FinancialTransaction $transaction) => $transaction->kind === 'capture' ? $this->matchCapture($transaction) : $this->matchProviderRefund($transaction));
    }

    private function matchStoreRefund(EventInbox $event): void
    {
        $providerRef = $event->payload['data']['provider_ref'] ?? null;

        if (! is_string($providerRef) || $providerRef === '') {
            return;
        }

        FinancialTransaction::query()
            ->where('tenant_id', $event->tenant_id)
            ->where('store_id', $event->store_id)
            ->where('kind', 'refund')
            ->where('external_operation_id', $providerRef)
            ->where('source_authority', Integration::SOURCE_INDEPENDENT_PROVIDER)
            ->get()
            ->each(fn (FinancialTransaction $transaction) => $this->matchProviderRefund($transaction));
    }

    private function matchCapture(FinancialTransaction $capture): void
    {
        if ($capture->source_authority !== Integration::SOURCE_INDEPENDENT_PROVIDER || $capture->status !== 'succeeded' || $capture->payment_id === null) {
            return;
        }

        $alreadyLinked = PaymentAllocation::query()->where('capture_transaction_id', $capture->id)->whereNull('revoked_at')->exists();
        /** @var Payment|null $payment */
        $payment = Payment::query()->find($capture->payment_id);

        if ($alreadyLinked || $payment === null) {
            return;
        }

        $references = array_values(array_filter([$payment->intent_ref, $payment->charge_ref, $capture->external_operation_id]));
        $orders = Order::query()
            ->where('tenant_id', $capture->tenant_id)
            ->where('store_id', $capture->store_id)
            ->where('mode', $payment->mode)
            ->where('currency', $capture->currency)
            ->whereIn('transaction_ref', $references)
            ->limit(2)
            ->get();

        if ($orders->count() !== 1) {
            return;
        }

        $this->attempt(fn () => $this->allocations->allocateCapture(
            $payment,
            $capture,
            $orders->first(),
            (int) $capture->amount_minor,
            PaymentAllocation::STRATEGY_EXACT_REFERENCE,
            ['matcher' => self::MATCHER_VERSION, 'reference' => $orders->first()->transaction_ref],
        ));
    }

    private function matchProviderRefund(FinancialTransaction $transaction): void
    {
        if ($transaction->kind !== 'refund' || $transaction->source_authority !== Integration::SOURCE_INDEPENDENT_PROVIDER || $transaction->status !== 'succeeded' || $transaction->payment_id === null) {
            return;
        }

        if (RefundAllocation::query()->where('refund_transaction_id', $transaction->id)->whereNull('revoked_at')->exists()) {
            return;
        }

        $allocations = PaymentAllocation::query()->where('payment_id', $transaction->payment_id)->whereNull('revoked_at')->get();
        $refunds = Refund::query()
            ->where('tenant_id', $transaction->tenant_id)
            ->where('store_id', $transaction->store_id)
            ->whereIn('order_id', $allocations->pluck('order_id'))
            ->where('provider_ref', $transaction->external_operation_id)
            ->whereIn('status', ['requested', 'recorded'])
            ->limit(2)
            ->get();

        if ($refunds->count() !== 1) {
            return;
        }

        $refund = $refunds->first();
        $allocation = $allocations->firstWhere('order_id', $refund->order_id);
        $amount = min((int) $transaction->amount_minor, (int) $refund->amount_minor);

        $this->attempt(fn () => $this->allocations->allocateRefund(
            $refund,
            $transaction,
            $allocation,
            $amount,
            PaymentAllocation::STRATEGY_EXACT_REFERENCE,
            ['matcher' => self::MATCHER_VERSION, 'reference' => $transaction->external_operation_id],
        ));
    }

    /** A rejected link (amount already allocated, scope or currency mismatch) is left for a person to review. */
    private function attempt(callable $link): void
    {
        try {
            $link();
        } catch (AllocationRejected) {
        }
    }
}
