<?php

namespace BusinessWatchdog\WooCommerce\Capture;

final class EventFactory
{
    public static function envelope(string $type, string $aggregateType, string $aggregateId, int $revision, ?string $occurredAt, array $data, bool $synthetic = false): array
    {
        $observedAt = gmdate('Y-m-d\TH:i:s\Z');

        return [
            'schema_version' => '1.0',
            'event_id' => wp_generate_uuid4(),
            'type' => $type,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'aggregate_revision' => $revision,
            'occurred_at' => $occurredAt !== null && strcmp($occurredAt, $observedAt) <= 0 ? $occurredAt : $observedAt,
            'observed_at' => $observedAt,
            'is_synthetic' => $synthetic,
            'data' => $data,
        ];
    }

    public static function isoDate($date): ?string
    {
        if ($date instanceof \DateTimeInterface) {
            return gmdate('Y-m-d\TH:i:s\Z', $date->getTimestamp());
        }

        return null;
    }
}
