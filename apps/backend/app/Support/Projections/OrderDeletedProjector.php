<?php

namespace App\Support\Projections;

use App\Models\EventInbox;
use App\Models\Order;
use App\Models\OrderRevision;
use App\Support\Ingest\EventProjectionResult;

class OrderDeletedProjector
{
    public const ERROR_INVALID = 'order_deleted_invalid';

    public const ERROR_ORDER_MISSING = 'order_deleted_order_missing';

    public const ERROR_REVISION_CONFLICT = OrderSnapshotProjector::ERROR_REVISION_CONFLICT;

    public function project(EventInbox $event): EventProjectionResult
    {
        if ($event->event_type !== EventInbox::EVENT_ORDER_DELETED || $event->aggregate_type !== EventInbox::AGGREGATE_ORDER) {
            return EventProjectionResult::ok();
        }

        $payload = $event->payload;
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : null;
        $sourceRevision = $event->aggregate_revision;

        if ($data === null || $sourceRevision === null || ! $this->isValidDeletedData($data)) {
            return EventProjectionResult::failed(self::ERROR_INVALID);
        }

        /** @var Order|null $order */
        $order = Order::query()
            ->where('integration_id', $event->integration_id)
            ->where('external_id', $event->aggregate_external_id)
            ->lockForUpdate()
            ->first();

        if ($order === null) {
            return EventProjectionResult::failed(self::ERROR_ORDER_MISSING);
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
        } elseif ($sourceRevision === $order->source_revision && $order->current_payload_hash !== $payloadHash) {
            return EventProjectionResult::failed(self::ERROR_REVISION_CONFLICT);
        }

        /** @var OrderRevision|null $revision */
        $revision = OrderRevision::query()
            ->where('order_id', $order->id)
            ->where('source_revision', $sourceRevision)
            ->lockForUpdate()
            ->first();

        if ($revision !== null) {
            if ($revision->payload_hash !== $payloadHash) {
                return EventProjectionResult::failed(self::ERROR_REVISION_CONFLICT);
            }

            return EventProjectionResult::ok();
        }

        OrderRevision::query()->create([
            'order_id' => $order->id,
            'source_revision' => $sourceRevision,
            'tenant_id' => $event->tenant_id,
            'store_id' => $event->store_id,
            'event_id' => $event->id,
            'snapshot' => $payload,
            'payload_hash' => $payloadHash,
            'observed_at' => $event->observed_at,
            'created_at' => $now,
        ]);

        return EventProjectionResult::ok();
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
