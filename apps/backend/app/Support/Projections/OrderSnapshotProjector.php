<?php

namespace App\Support\Projections;

use App\Models\EventInbox;
use App\Models\Order;
use App\Models\OrderRevision;
use App\Support\Ingest\EventProjectionResult;

class OrderSnapshotProjector
{
    public function project(EventInbox $event): EventProjectionResult
    {
        if ($event->event_type !== 'order.snapshot' || $event->aggregate_type !== 'order') {
            return EventProjectionResult::ok();
        }

        $payload = $event->payload;
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : null;
        $sourceRevision = $event->aggregate_revision;

        if ($data === null || $sourceRevision === null || ! $this->isValidOrderData($data)) {
            return EventProjectionResult::failed('order_snapshot_invalid');
        }

        /** @var Order|null $order */
        $order = Order::query()
            ->where('integration_id', $event->integration_id)
            ->where('external_id', $event->aggregate_external_id)
            ->lockForUpdate()
            ->first();

        $payloadHash = $event->payload_hash;
        $now = now();

        if ($order === null) {
            $order = Order::query()->create($this->orderAttributes($event, $data, $sourceRevision, $payloadHash, $now));
        } elseif ($sourceRevision > $order->source_revision) {
            $attributes = $this->orderAttributes($event, $data, $sourceRevision, $payloadHash, $now);
            unset($attributes['created_at']);

            $order->forceFill($attributes)->save();
        } elseif ($sourceRevision === $order->source_revision && $order->current_payload_hash !== $payloadHash) {
            return EventProjectionResult::failed('order_revision_conflict');
        }

        /** @var OrderRevision|null $revision */
        $revision = OrderRevision::query()
            ->where('order_id', $order->id)
            ->where('source_revision', $sourceRevision)
            ->lockForUpdate()
            ->first();

        if ($revision !== null) {
            if ($revision->payload_hash !== $payloadHash) {
                return EventProjectionResult::failed('order_revision_conflict');
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
    private function isValidOrderData(array $data): bool
    {
        return is_string($data['status'] ?? null)
            && is_string($data['currency'] ?? null)
            && preg_match('/^[A-Z]{3}$/', $data['currency']) === 1
            && is_int($data['currency_exponent'] ?? null)
            && $data['currency_exponent'] >= 0
            && $data['currency_exponent'] <= 6
            && is_string($data['total_minor'] ?? null)
            && preg_match('/^(0|[1-9][0-9]{0,18})$/', $data['total_minor']) === 1
            && is_bool($data['payment_expected'] ?? null)
            && (! array_key_exists('mode', $data) || in_array($data['mode'], ['live', 'test'], true))
            && (! array_key_exists('financial_support', $data) || in_array($data['financial_support'], ['supported', 'unsupported', 'unknown'], true));
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function orderAttributes(EventInbox $event, array $data, int $sourceRevision, string $payloadHash, mixed $now): array
    {
        return [
            'tenant_id' => $event->tenant_id,
            'store_id' => $event->store_id,
            'integration_id' => $event->integration_id,
            'external_id' => $event->aggregate_external_id,
            'display_number' => ProjectionValueNormalizer::stringOrDefault($data['display_number'] ?? null, $event->aggregate_external_id),
            'source_revision' => $sourceRevision,
            'status' => $data['status'],
            'gateway' => ProjectionValueNormalizer::nullableString($data['gateway'] ?? null),
            'mode' => ProjectionValueNormalizer::stringOrDefault($data['mode'] ?? null, 'live'),
            'currency' => $data['currency'],
            'currency_exponent' => $data['currency_exponent'],
            'total_minor' => (int) $data['total_minor'],
            'payment_expected' => $data['payment_expected'],
            'paid_marked_at' => ProjectionValueNormalizer::nullableDateTime($data['paid_marked_at'] ?? null),
            'transaction_ref' => ProjectionValueNormalizer::nullableString($data['transaction_ref'] ?? null),
            'financial_support' => ProjectionValueNormalizer::stringOrDefault($data['financial_support'] ?? null, 'unknown'),
            'is_synthetic' => $event->is_synthetic,
            'source_created_at' => ProjectionValueNormalizer::nullableDateTime($data['source_created_at'] ?? null) ?? $event->occurred_at,
            'source_updated_at' => ProjectionValueNormalizer::nullableDateTime($data['source_updated_at'] ?? null) ?? $event->occurred_at,
            'deleted_at' => null,
            'current_payload_hash' => $payloadHash,
            'metadata' => [],
            'updated_at' => $now,
            'created_at' => $now,
        ];
    }

}
