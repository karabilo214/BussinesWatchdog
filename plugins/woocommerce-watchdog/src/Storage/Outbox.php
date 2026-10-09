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

    public static function due(int $limit, int $maxBytes): array
    {
        global $wpdb;

        $rows = (array) $wpdb->get_results($wpdb->prepare(
            'SELECT id, event_id, payload, attempts FROM ' . Schema::outboxTable() . ' WHERE state = %s AND next_attempt_at <= %s ORDER BY id LIMIT %d',
            self::STATE_PENDING,
            gmdate('Y-m-d H:i:s'),
            $limit
        ), ARRAY_A);

        $batch = [];
        $bytes = 16;

        foreach ($rows as $row) {
            $size = strlen((string) $row['payload']) + 1;

            if ($batch !== [] && $bytes + $size > $maxBytes) {
                break;
            }

            $batch[] = $row;
            $bytes += $size;
        }

        return $batch;
    }

    public static function deleteByIds(array $ids): void
    {
        self::updateByIds($ids, null);
    }

    public static function deadLetter(array $ids, string $code): void
    {
        self::updateByIds($ids, ['state' => self::STATE_DEAD_LETTER, 'last_error' => substr($code, 0, 191)]);
    }

    public static function retryLater(array $rows, string $code, ?int $retryAfterSeconds): void
    {
        global $wpdb;

        foreach ($rows as $row) {
            $attempts = (int) $row['attempts'] + 1;
            $delay = max(self::backoffSeconds($attempts), (int) $retryAfterSeconds);
            $wpdb->update(Schema::outboxTable(), [
                'attempts' => $attempts,
                'next_attempt_at' => gmdate('Y-m-d H:i:s', time() + $delay),
                'last_error' => substr($code, 0, 191),
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ], ['id' => (int) $row['id']], ['%d', '%s', '%s', '%s'], ['%d']);
        }
    }

    public static function backoffSeconds(int $attempts): int
    {
        $schedule = [30, 120, 600, 3600, 21600];
        $base = $schedule[min(max($attempts, 1), count($schedule)) - 1];
        $jitter = (int) round($base * 0.2);

        return max(1, $base + random_int(-$jitter, $jitter));
    }

    private static function updateByIds(array $ids, ?array $values): void
    {
        global $wpdb;

        $ids = array_values(array_filter(array_map('intval', $ids)));

        if ($ids === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $table = Schema::outboxTable();

        if ($values === null) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE id IN ({$placeholders})", $ids));

            return;
        }

        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET state = %s, last_error = %s, updated_at = %s WHERE id IN ({$placeholders})",
            array_merge([$values['state'], $values['last_error'], gmdate('Y-m-d H:i:s')], $ids)
        ));
    }
}
