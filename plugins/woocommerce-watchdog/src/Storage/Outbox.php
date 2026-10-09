<?php

namespace BusinessWatchdog\WooCommerce\Storage;

final class Outbox
{
    public const STATE_PENDING = 'pending';

    public const STATE_DEAD_LETTER = 'dead_letter';

    public static function enqueue(array $envelope, string $aggregateKey): void
    {
        global $wpdb;

        $payload = (string) wp_json_encode($envelope);
        $now = gmdate('Y-m-d H:i:s');

        $wpdb->insert(Schema::outboxTable(), [
            'event_id' => (string) $envelope['event_id'],
            'aggregate_key' => $aggregateKey,
            'revision' => (int) ($envelope['aggregate_revision'] ?? 0),
            'event_type' => (string) $envelope['type'],
            'payload' => $payload,
            'payload_hash' => hash('sha256', $payload),
            'state' => self::STATE_PENDING,
            'attempts' => 0,
            'next_attempt_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s']);

        if ($wpdb->last_error !== '') {
            throw new \RuntimeException('outbox_write_failed');
        }
    }

    public static function pendingFor(string $aggregateKey): array
    {
        global $wpdb;

        return (array) $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . Schema::outboxTable() . ' WHERE aggregate_key = %s ORDER BY id',
            $aggregateKey
        ), ARRAY_A);
    }
}
