<?php

namespace BusinessWatchdog\WooCommerce\Storage;

final class OutboxStats
{
    public static function backlogCount(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM " . Schema::outboxTable() . " WHERE state = 'pending'");
    }

    public static function oldestPendingAt(): ?string
    {
        global $wpdb;

        $value = $wpdb->get_var("SELECT MIN(created_at) FROM " . Schema::outboxTable() . " WHERE state = 'pending'");

        return $value ? gmdate('Y-m-d\TH:i:s\Z', strtotime($value . ' UTC')) : null;
    }

    public static function deadLetterCount(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM " . Schema::outboxTable() . " WHERE state = 'dead_letter'");
    }
}
