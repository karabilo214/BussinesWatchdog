<?php

namespace BusinessWatchdog\WooCommerce\Storage;

final class LegacyNames
{
    public const PREFIX = 'bw_';

    public const PAYMENT_MODE_META = '_bw_payment_mode';

    public const SYNTHETIC_META = '_bw_synthetic_run';

    private const TABLES = ['outbox', 'revisions', 'state', 'payment_attempts', 'checkout_observation'];

    private const OPTIONS = [
        'schema_version' => false,
        'monitoring_stopped' => true,
        'outbox_full' => true,
        'checkout_observation' => true,
        'checkout_script_altered_at' => false,
        'purge_history_on_uninstall' => false,
    ];

    public const HOOKS = ['heartbeat', 'deliver_outbox', 'rescan_recent', 'backfill', 'daily_audit', 'payment_attempts', 'checkout_observation', 'synthetic_cleanup'];

    public static function pending(): bool
    {
        foreach (array_keys(self::OPTIONS) as $name) {
            if (get_option(self::PREFIX . $name, null) !== null) {
                return true;
            }
        }

        return false;
    }

    public static function migrate(): void
    {
        global $wpdb;

        foreach (self::TABLES as $name) {
            $legacy = $wpdb->prefix . self::PREFIX . $name;
            $current = $wpdb->prefix . 'business_watchdog_' . $name;

            if (self::tableExists($legacy) && ! self::tableExists($current)) {
                $wpdb->query("RENAME TABLE `{$legacy}` TO `{$current}`");
            }
        }

        foreach (self::OPTIONS as $name => $autoload) {
            $value = get_option(self::PREFIX . $name, null);

            if ($value === null) {
                continue;
            }

            if (get_option('business_watchdog_' . $name, null) === null) {
                update_option('business_watchdog_' . $name, $value, $autoload);
            }

            delete_option(self::PREFIX . $name);
        }

        self::clearHooks();
    }

    public static function clearHooks(): void
    {
        foreach (self::HOOKS as $hook) {
            wp_clear_scheduled_hook(self::PREFIX . $hook);

            if (function_exists('as_unschedule_all_actions')) {
                as_unschedule_all_actions(self::PREFIX . $hook, [], 'business-watchdog');
            }
        }
    }

    private static function tableExists(string $table): bool
    {
        global $wpdb;

        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
    }
}
