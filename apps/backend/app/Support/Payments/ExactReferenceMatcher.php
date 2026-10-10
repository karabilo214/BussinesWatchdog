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
use App\Models\Store;

/**
 * Spec §13 in order. 1) Exact gateway reference: exactly one order of the store names the payment intent, charge or
 * capture as its transaction reference (same mode and currency). 2) Only when no order has the reference: provider
 * metadata naming the store's order id, accepted only when the recorded site origin is the store's confirmed domain
 * (`verified_metadata`). A provider refund is linked to the store refund that names it. Ambiguity links nothing —
 * the payment stays in the unmatched list for a person to decide. Amount and time never link anything here.
 */
class ExactReferenceMatcher
{
    public const MATCHER_VERSION = 'exact_reference_v1';

    public const METADATA_MATCHER_VERSION = 'verified_metadata_v1';

    public function __construct(
        private readonly PaymentAllocationService $allocations,
    ) {}

    public function matchForEvent(EventInbox $event): void
    {
        match ($event->event_type) {
            EventInbox::EVENT_TRANSACTION_OBSERVED => $this->matchTransaction($event),
            EventInbox::EVENT_PAYMENT_SNAPSHOT => $this->matchPayment($event),
            EventInbox::EVENT_REFUND_SNAPSHOT => $this->matchStoreRefund($event),
            EventInbox::EVENT_ORDER_SNAPSHOT => $this->matchArrivedOrder($event),
            default => null,
        };
    }

    /** Retry every unlinked provider capture of a store, e.g. once its domain is confirmed or in the nightly sweep. */
    public function matchUnlinkedCaptures(string $tenantId, string $storeId): void
    {
        FinancialTransaction::query()
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where('kind', 'capture')
            ->where('status', 'succeeded')
            ->where('source_authority', Integration::SOURCE_INDEPENDENT_PROVIDER)
            ->whereNotIn('id', PaymentAllocation::query()->where('store_id', $storeId)->whereNull('revoked_at')->select('capture_transaction_id'))
            ->orderBy('id')
            ->get()
            ->each(fn (FinancialTransaction $capture) => $this->matchCapture($capture));
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

        if ($orders->count() > 1) {
            return;
        }

        $linked = $orders->count() === 1
            ? $this->attempt(fn () => $this->allocations->allocateCapture(
                $payment,
                $capture,
                $orders->first(),
                (int) $capture->amount_minor,
                PaymentAllocation::STRATEGY_EXACT_REFERENCE,
                ['matcher' => self::MATCHER_VERSION, 'reference' => $orders->first()->transaction_ref],
            ))
            : $this->matchByMetadata($payment, $capture);

        if ($linked) {
            FinancialTransaction::query()
                ->where('payment_id', $payment->id)
                ->where('kind', 'refund')
                ->get()
                ->each(fn (FinancialTransaction $refund) => $this->matchProviderRefund($refund));
        }
    }

    private function matchByMetadata(Payment $payment, FinancialTransaction $capture): bool
    {
        $orderRef = $payment->metadata['provider_order_ref'] ?? null;
        $siteOrigin = $payment->metadata['provider_site_origin'] ?? null;
        /** @var Store|null $store */
        $store = Store::query()->where('tenant_id', $capture->tenant_id)->whereKey($capture->store_id)->first();

        if (! is_string($orderRef) || ! is_string($siteOrigin) || $store === null || $store->verified_at === null || StoreOrigin::of($store->base_url) !== $siteOrigin) {
            return false;
        }

        $orders = Order::query()
            ->where('tenant_id', $capture->tenant_id)
            ->where('store_id', $capture->store_id)
            ->where('external_id', $orderRef)
            ->where('mode', $payment->mode)
            ->where('currency', $capture->currency)
            ->limit(2)
            ->get();

        if ($orders->count() !== 1) {
            return false;
        }

        return $this->attempt(fn () => $this->allocations->allocateCapture(
            $payment,
            $capture,
            $orders->first(),
            (int) $capture->amount_minor,
            PaymentAllocation::STRATEGY_VERIFIED_METADATA,
            ['matcher' => self::METADATA_MATCHER_VERSION, 'order_ref' => $orderRef, 'site_origin' => $siteOrigin],
        ));
    }

    /** An order can arrive after its payment: look for unlinked captures that name it by reference or metadata. */
    private function matchArrivedOrder(EventInbox $event): void
    {
        /** @var Order|null $order */
        $order = Order::query()
            ->where('tenant_id', $event->tenant_id)
            ->where('integration_id', $event->integration_id)
            ->where('external_id', $event->aggregate_external_id)
            ->first();

        if ($order === null) {
            return;
        }

        $paymentIds = Payment::query()
            ->where('tenant_id', $order->tenant_id)
            ->where('store_id', $order->store_id)
            ->where('source_authority', Integration::SOURCE_INDEPENDENT_PROVIDER)
            ->where(function ($query) use ($order): void {
                if ($order->transaction_ref !== null && $order->transaction_ref !== '') {
                    $query->orWhere('intent_ref', $order->transaction_ref)->orWhere('charge_ref', $order->transaction_ref);
                }

                $query->orWhere('metadata->provider_order_ref', $order->external_id);
            })
            ->pluck('id');

        FinancialTransaction::query()
            ->where('tenant_id', $order->tenant_id)
            ->where('kind', 'capture')
            ->where(function ($query) use ($paymentIds, $order): void {
                $query->whereIn('payment_id', $paymentIds);

                if ($order->transaction_ref !== null && $order->transaction_ref !== '') {
                    $query->orWhere(fn ($byOperation) => $byOperation->where('store_id', $order->store_id)->where('external_operation_id', $order->transaction_ref));
                }
            })
            ->get()
            ->each(fn (FinancialTransaction $capture) => $this->matchCapture($capture));
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
    private function attempt(callable $link): bool
    {
        try {
            $link();

            return true;
        } catch (AllocationRejected) {
            return false;
        }
    }
}
