<?php

namespace App\Support\Projections;

use App\Models\EventInbox;
use App\Models\Order;
use App\Models\OrderRevision;

class OrderDeletedProjector
{
    public function project(EventInbox $event): bool
    {
        if ($event->event_type !== 'order.deleted' || $event->aggregate_type !== 'order') {
            return true;
        }

        $payload = $event->payload;
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : null;
        $sourceRevision = $event->aggregate_revision;

        if ($data === null || $sourceRevision === null || ! $this->isValidDeletedData($data)) {
            return false;
        }

        /** @var Order|null $order */
        $order = Order::query()
            ->where('integration_id', $event->integration_id)
            ->where('external_id', $event->aggregate_external_id)
            ->lockForUpdate()
            ->first();

        if ($order === null) {
            return false;
        }

        $payloadHash = $event->payload_hash;
        $now = now();

        if ($sourceRevision > $order->source_revision) {
            $order->forceFill([
                'source_revision' => $sourceRevision,
                'deleted_at' => $event->occurred_at,
                'current_payload_hash' => $payloadHash,
                'updated_at' => $now,
            ])->save();
        }

        OrderRevision::query()->firstOrCreate([
            'order_id' => $order->id,
            'source_revision' => $sourceRevision,
        ], [
            'tenant_id' => $event->tenant_id,
            'store_id' => $event->store_id,
            'event_id' => $event->id,
            'snapshot' => $payload,
            'payload_hash' => $payloadHash,
            'observed_at' => $event->observed_at,
            'created_at' => $now,
        ]);

        return true;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function isValidDeletedData(array $data): bool
    {
        return is_string($data['reason_code'] ?? null)
            && $data['reason_code'] !== ''
            && mb_strlen($data['reason_code']) <= 255;
    }
}
