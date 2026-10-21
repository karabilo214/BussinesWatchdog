<?php

namespace BusinessWatchdog\WooCommerce\Storage;

final class Outbox
{
    public const STATE_PENDING = 'pending';

    public const STATE_DEAD_LETTER = 'dead_letter';

    public const LIMIT = 100000;

    public const RECOVER_BELOW = 90000;

    public const FULL_OPTION = 'bw_outbox_full';

    public static function full(): bool
    {
        return is_array(get_option(self::FULL_OPTION, null));
    }

    public static function fullState(): ?array
    {
        $value = get_option(self::FULL_OPTION, null);

        return is_array($value) ? $value : null;
    }

    public static function noteSkipped(): void
    {
        $state = self::fullState();

        if ($state !== null) {
            $state['skipped'] = (int) ($state['skipped'] ?? 0) + 1;
            update_option(self::FULL_OPTION, $state, true);
        }
    }

    public static function checkLimit(?int $limit = null, ?int $recoverBelow = null): array
    {
        $limit = $limit ?? self::LIMIT;
        $recoverBelow = $recoverBelow ?? self::RECOVER_BELOW;
        $total = OutboxStats::totalCount();
        $state = self::fullState();

        if ($state === null && $total >= $limit) {
            update_option(self::FULL_OPTION, ['since' => gmdate('c'), 'skipped' => 0], true);

            return ['full' => true, 'total' => $total, 'recovered' => false];
        }

        if ($state !== null && $total < $recoverBelow) {
            delete_option(self::FULL_OPTION);
            State::set('outbox_last_recovery', ['at' => gmdate('c'), 'full_since' => $state['since'] ?? null, 'skipped' => (int) ($state['skipped'] ?? 0)]);
            \BusinessWatchdog\WooCommerce\Jobs\BackfillJob::start('queue_recovery');

            return ['full' => false, 'total' => $total, 'recovered' => true];
        }

        return ['full' => $state !== null, 'total' => $total, 'recovered' => false];
    }

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
            "SELECT * FROM {$wpdb->prefix}bw_outbox WHERE aggregate_key = %s ORDER BY id",
            $aggregateKey
        ), ARRAY_A);
    }

    public static function due(int $limit, int $maxBytes): array
    {
        global $wpdb;

        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT id, event_id, payload, attempts FROM {$wpdb->prefix}bw_outbox WHERE state = %s AND next_attempt_at <= %s ORDER BY id LIMIT %d",
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

        if ($values === null) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}bw_outbox WHERE id IN ({$placeholders})", $ids));

            return;
        }

        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}bw_outbox SET state = %s, last_error = %s, updated_at = %s WHERE id IN ({$placeholders})",
            array_merge([$values['state'], $values['last_error'], gmdate('Y-m-d H:i:s')], $ids)
        ));
    }
}
