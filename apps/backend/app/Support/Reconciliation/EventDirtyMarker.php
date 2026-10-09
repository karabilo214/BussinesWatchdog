<?php

namespace App\Support\Reconciliation;

use App\Models\EventInbox;
use App\Models\FinancialTransaction;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\ReconciliationDirtySubject;

class EventDirtyMarker
{
    public function __construct(
        private readonly DirtySubjectMarker $marker,
    ) {}

    public function markForEvent(EventInbox $event): void
    {
        match ($event->event_type) {
            EventInbox::EVENT_ORDER_SNAPSHOT, EventInbox::EVENT_ORDER_DELETED => $this->markOrderByExternalId(
                $event,
                $event->aggregate_external_id,
                ReconciliationDirtySubject::REASON_ORDER_EVENT,
            ),
            EventInbox::EVENT_REFUND_SNAPSHOT => $this->markOrderByExternalId(
                $event,
                $event->payload['data']['order_id'] ?? null,
                ReconciliationDirtySubject::REASON_REFUND_EVENT,
            ),
            EventInbox::EVENT_PAYMENT_SNAPSHOT => $this->markPayment(
                $event,
                Payment::query()
                    ->where('tenant_id', $event->tenant_id)
                    ->where('integration_id', $event->integration_id)
                    ->where('external_id', $event->aggregate_external_id)
                    ->value('id'),
            ),
            EventInbox::EVENT_TRANSACTION_OBSERVED => $this->markPayment(
                $event,
                FinancialTransaction::query()
                    ->where('tenant_id', $event->tenant_id)
                    ->where('integration_id', $event->integration_id)
                    ->where('external_operation_id', $event->payload['data']['external_operation_id'] ?? null)
                    ->value('payment_id'),
            ),
            default => null,
        };
    }

    private function markOrderByExternalId(EventInbox $event, mixed $externalId, string $reason): void
    {
        if (! is_string($externalId) || $externalId === '') {
            return;
        }

        $orderId = Order::query()
            ->where('tenant_id', $event->tenant_id)
            ->where('integration_id', $event->integration_id)
            ->where('external_id', $externalId)
            ->value('id');

        if ($orderId !== null) {
            $this->marker->markOrder($event->tenant_id, $event->store_id, $orderId, $reason);
        }
    }

    private function markPayment(EventInbox $event, ?string $paymentId): void
    {
        $this->marker->markStoreUnmatchedPayments(
            $event->tenant_id,
            $event->store_id,
            ReconciliationDirtySubject::REASON_PAYMENT_EVENT,
        );

        if ($paymentId === null) {
            return;
        }

        $orderIds = PaymentAllocation::query()
            ->where('tenant_id', $event->tenant_id)
            ->where('store_id', $event->store_id)
            ->where('payment_id', $paymentId)
            ->whereNull('revoked_at')
            ->distinct()
            ->pluck('order_id');

        foreach ($orderIds as $orderId) {
            $this->marker->markOrder($event->tenant_id, $event->store_id, $orderId, ReconciliationDirtySubject::REASON_PAYMENT_EVENT);
        }
    }
}
