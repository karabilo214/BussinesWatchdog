<?php

namespace BusinessWatchdog\WooCommerce\Storage;

final class OutboxStats
{
    public static function backlogCount(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}business_watchdog_outbox WHERE state = 'pending'");
    }

    public static function totalCount(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}business_watchdog_outbox");
    }

    public static function backlogOlderThanDays(int $days): bool
    {
        $oldest = self::oldestPendingAt();

        return $oldest !== null && strtotime($oldest) <= time() - $days * 86400;
    }

    public static function oldestPendingAt(): ?string
    {
        global $wpdb;

        $value = $wpdb->get_var("SELECT MIN(created_at) FROM {$wpdb->prefix}business_watchdog_outbox WHERE state = 'pending'");

        return $value ? gmdate('Y-m-d\TH:i:s\Z', strtotime($value . ' UTC')) : null;
    }

    public static function deadLetterCount(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}business_watchdog_outbox WHERE state = 'dead_letter'");
    }
}
