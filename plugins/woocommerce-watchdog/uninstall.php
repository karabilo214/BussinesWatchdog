<?php

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

try {
    require_once __DIR__ . '/src/Autoloader.php';

    if (class_exists('\BusinessWatchdog\WooCommerce\Autoloader')) {
        \BusinessWatchdog\WooCommerce\Autoloader::register(__DIR__ . '/src');
        \BusinessWatchdog\WooCommerce\Storage\LegacyNames::migrate();
    }

    foreach (['business_watchdog_heartbeat', 'business_watchdog_deliver_outbox', 'business_watchdog_rescan_recent', 'business_watchdog_backfill', 'business_watchdog_daily_audit', 'business_watchdog_payment_attempts', 'business_watchdog_checkout_observation', 'business_watchdog_synthetic_cleanup'] as $business_watchdog_hook) {
        wp_clear_scheduled_hook($business_watchdog_hook);

        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions($business_watchdog_hook, [], 'business-watchdog');
        }
    }

    delete_option('business_watchdog_monitoring_stopped');
    delete_option('business_watchdog_outbox_full');
    delete_option('business_watchdog_checkout_observation');
    delete_option('business_watchdog_checkout_script_altered_at');
    delete_metadata('user', 0, 'business_watchdog_connect', '', true);
    delete_metadata('user', 0, 'business_watchdog_card_hidden', '', true);

    global $wpdb;

    $business_watchdog_state_table = $wpdb->prefix . 'business_watchdog_state';

    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($business_watchdog_state_table))) === $business_watchdog_state_table) {
        $wpdb->delete($business_watchdog_state_table, ['name' => 'connection'], ['%s']);
    }

    if (get_option('business_watchdog_purge_history_on_uninstall') === 'yes'
        && class_exists('\BusinessWatchdog\WooCommerce\Storage\Schema')) {
        \BusinessWatchdog\WooCommerce\Storage\Schema::drop();
        delete_option('business_watchdog_purge_history_on_uninstall');
    }
} catch (\Throwable $exception) {
    error_log('business-watchdog: uninstall_failed');
}
