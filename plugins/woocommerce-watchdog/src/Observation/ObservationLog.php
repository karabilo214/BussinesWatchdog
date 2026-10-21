<?php

namespace BusinessWatchdog\WooCommerce\Observation;

use BusinessWatchdog\WooCommerce\Jobs\PaymentAttemptsJob;

/**
 * Checkout observation counters per 5-minute window (ADR 0059). Only numbers: no visitor, address, agent or form data.
 * A window that changes after it was reported is reported again with a higher revision.
 */
final class ObservationLog
{
    public const COUNTERS = ['checkout_rendered', 'checkout_rendered_noscript', 'finish_rendered', 'script_loaded', 'form_ready', 'returned_with_error', 'submit_clicked', 'submit_stalled', 'finish_reached', 'js_errors'];

    public const MAX_PER_WINDOW = 10000;

    public static function increment(array $counts, ?int $now = null): void
    {
        global $wpdb;

        $counts = array_intersect_key($counts, array_flip(self::COUNTERS));

        if ($counts === []) {
            return;
        }

        $columns = array_keys($counts);
        $values = array_map('intval', array_values($counts));
        $updates = array_map(static function (string $column): string {
            return $column . ' = LEAST(' . $column . ' + VALUES(' . $column . '), ' . self::MAX_PER_WINDOW . ')';
        }, $columns);

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- column names come only from self::COUNTERS (array_intersect_key above); every value is a placeholder.
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->prefix}bw_checkout_observation (window_start, " . implode(', ', $columns) . ', revision, reported) VALUES (%s'
            . str_repeat(', %d', count($values)) . ', 1, 0) ON DUPLICATE KEY UPDATE ' . implode(', ', $updates)
            . ', revision = revision + reported, reported = 0',
            array_merge([gmdate('Y-m-d H:i:s', PaymentAttemptsJob::windowStart($now ?? time()))], $values)
        ));
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    }

    /**
     * Windows that ended at least a minute ago and were not reported in their current revision.
     */
    public static function unreportedClosed(int $now, int $limit): array
    {
        global $wpdb;

        $closedBefore = gmdate('Y-m-d H:i:s', $now - PaymentAttemptsJob::WINDOW_SECONDS - 60);

        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bw_checkout_observation WHERE reported = 0 AND window_start <= %s ORDER BY window_start LIMIT %d",
            $closedBefore,
            $limit
        ), ARRAY_A);
    }

    public static function markReported(string $windowStart, int $revision): void
    {
        global $wpdb;

        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}bw_checkout_observation SET reported = 1 WHERE window_start = %s AND revision = %d",
            $windowStart,
            $revision
        ));
    }

    public static function prune(int $days): void
    {
        global $wpdb;

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}bw_checkout_observation WHERE reported = 1 AND window_start < %s",
            gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS)
        ));
    }
}
