<?php

namespace App\Support\Projections;

use App\Models\EventInbox;
use App\Models\Order;
use App\Models\Refund;
use App\Support\Ingest\EventProjectionResult;

class RefundSnapshotProjector
{
    public function project(EventInbox $event): EventProjectionResult
    {
        if ($event->event_type !== 'refund.snapshot' || $event->aggregate_type !== 'refund') {
            return EventProjectionResult::ok();
        }

        $payload = $event->payload;
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : null;
        $sourceRevision = $event->aggregate_revision;

        if ($data === null || $sourceRevision === null || ! $this->isValidRefundData($data)) {
            return EventProjectionResult::failed('refund_snapshot_invalid');
        }

        /** @var Order|null $order */
        $order = Order::query()
            ->where('integration_id', $event->integration_id)
            ->where('external_id', $data['order_id'])
            ->lockForUpdate()
            ->first();

        if ($order === null) {
            return EventProjectionResult::failed('refund_order_missing');
        }

        /** @var Refund|null $refund */
        $refund = Refund::query()
            ->where('integration_id', $event->integration_id)
            ->where('external_id', $event->aggregate_external_id)
            ->lockForUpdate()
            ->first();

        $now = now();

        if ($refund === null) {
            Refund::query()->create($this->refundAttributes($event, $data, $order, $sourceRevision, $now));

            return EventProjectionResult::ok();
        }

        if ($sourceRevision > $refund->source_revision) {
            $refund->forceFill($this->refundAttributes($event, $data, $order, $sourceRevision, $now))->save();
        }

        return EventProjectionResult::ok();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function isValidRefundData(array $data): bool
    {
        return is_string($data['order_id'] ?? null)
            && $data['order_id'] !== ''
            && is_string($data['currency'] ?? null)
            && preg_match('/^[A-Z]{3}$/', $data['currency']) === 1
            && is_int($data['currency_exponent'] ?? null)
            && $data['currency_exponent'] >= 0
            && $data['currency_exponent'] <= 6
            && is_string($data['amount_minor'] ?? null)
            && preg_match('/^(0|[1-9][0-9]{0,18})$/', $data['amount_minor']) === 1
            && (is_bool($data['external_required'] ?? null) || ($data['external_required'] ?? null) === null)
            && in_array($data['status'] ?? null, ['requested', 'recorded', 'cancelled', 'deleted'], true);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function refundAttributes(EventInbox $event, array $data, Order $order, int $sourceRevision, mixed $now): array
    {
        return [
            'tenant_id' => $event->tenant_id,
            'store_id' => $event->store_id,
            'integration_id' => $event->integration_id,
            'order_id' => $order->id,
            'external_id' => $event->aggregate_external_id,
            'source_revision' => $sourceRevision,
            'currency' => $data['currency'],
            'currency_exponent' => $data['currency_exponent'],
            'amount_minor' => (int) $data['amount_minor'],
            'external_required' => $data['external_required'],
            'provider_ref' => $this->nullableString($data['provider_ref'] ?? null),
            'status' => $data['status'],
            'occurred_at' => $event->occurred_at,
            'updated_at' => $now,
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
