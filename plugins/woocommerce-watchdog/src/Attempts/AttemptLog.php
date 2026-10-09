<?php

namespace BusinessWatchdog\WooCommerce\Attempts;

use BusinessWatchdog\WooCommerce\Storage\Schema;

final class AttemptLog
{
    public const PAID = 'paid';

    public const ON_HOLD = 'on_hold';

    public const FAILED = 'failed';

    public const PENDING_STUCK = 'pending_stuck';

    public const LATE_SUCCESS = 'late_success';

    public const REJECTED = 'rejected_before_order';

    public const SUCCESS_OUTCOMES = [self::PAID, self::ON_HOLD, self::LATE_SUCCESS];

    public const FAILURE_OUTCOMES = [self::FAILED, self::PENDING_STUCK];

    public static function start(int $orderId, string $paymentMethod): void
    {
        global $wpdb;

        self::resolveOpen($orderId, self::FAILED, 'retried');

        $wpdb->insert(Schema::attemptsTable(), [
            'order_id' => $orderId,
            'payment_method' => self::method($paymentMethod),
            'attempted_at' => self::now(),
        ], ['%d', '%s', '%s']);
    }

    public static function reject(string $paymentMethod, string $class): void
    {
        global $wpdb;

        $now = self::now();
        $wpdb->insert(Schema::attemptsTable(), [
            'payment_method' => self::method($paymentMethod),
            'outcome' => self::REJECTED,
            'failure_class' => self::failureClass($class),
            'attempted_at' => $now,
            'resolved_at' => $now,
        ], ['%s', '%s', '%s', '%s', '%s']);
    }

    public static function resolveOpen(int $orderId, string $outcome, ?string $class): int
    {
        global $wpdb;

        return (int) $wpdb->query($wpdb->prepare(
            'UPDATE ' . Schema::attemptsTable() . ' SET outcome = %s, failure_class = %s, resolved_at = %s WHERE order_id = %d AND outcome IS NULL',
            $outcome,
            $class === null ? '' : self::failureClass($class),
            self::now(),
            $orderId
        ));
    }

    public static function succeed(int $orderId, string $outcome): void
    {
        global $wpdb;

        if (self::resolveOpen($orderId, $outcome, null) > 0) {
            return;
        }

        $wpdb->query($wpdb->prepare(
            'UPDATE ' . Schema::attemptsTable() . " SET outcome = %s, failure_class = '', resolved_at = %s, reported = 0 WHERE order_id = %d AND outcome = %s",
            self::LATE_SUCCESS,
            self::now(),
            $orderId,
            self::PENDING_STUCK
        ));
    }

    public static function hasOpen(int $orderId): bool
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . Schema::attemptsTable() . ' WHERE order_id = %d AND outcome IS NULL',
            $orderId
        )) > 0;
    }

    public static function openBefore(int $timestamp, int $limit): array
    {
        global $wpdb;

        return (array) $wpdb->get_results($wpdb->prepare(
            'SELECT id, order_id, attempted_at FROM ' . Schema::attemptsTable() . ' WHERE outcome IS NULL AND attempted_at <= %s ORDER BY id LIMIT %d',
            gmdate('Y-m-d H:i:s', $timestamp),
            $limit
        ), ARRAY_A);
    }

    public static function unreportedBefore(string $before, int $limit): array
    {
        global $wpdb;

        return (array) $wpdb->get_results($wpdb->prepare(
            'SELECT id, payment_method, outcome, failure_class, resolved_at FROM ' . Schema::attemptsTable()
            . ' WHERE reported = 0 AND outcome IS NOT NULL AND resolved_at < %s ORDER BY resolved_at, id LIMIT %d',
            $before,
            $limit
        ), ARRAY_A);
    }

    public static function markReported(array $ids): void
    {
        global $wpdb;

        $ids = array_values(array_filter(array_map('intval', $ids)));

        if ($ids === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $wpdb->query($wpdb->prepare('UPDATE ' . Schema::attemptsTable() . " SET reported = 1 WHERE id IN ({$placeholders})", $ids));
    }

    public static function prune(int $days): void
    {
        global $wpdb;

        $before = gmdate('Y-m-d H:i:s', time() - $days * 86400);
        $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . Schema::attemptsTable() . ' WHERE attempted_at < %s AND (reported = 1 OR outcome IS NULL)',
            $before
        ));
    }

    public static function method($value): string
    {
        $method = substr(sanitize_key((string) $value), 0, 100);

        return $method === '' ? 'unknown' : $method;
    }

    public static function failureClass(string $class): string
    {
        $class = strtolower((string) preg_replace('/[^a-z0-9_]+/i', '_', $class));
        $class = trim($class, '_');

        if ($class === '' || ! preg_match('/^[a-z]/', $class)) {
            return 'unknown';
        }

        return substr($class, 0, 40);
    }

    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
