<?php

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

try {
    require_once __DIR__ . '/src/Autoloader.php';

    if (class_exists('\BusinessWatchdog\WooCommerce\Autoloader')) {
        \BusinessWatchdog\WooCommerce\Autoloader::register(__DIR__ . '/src');
    }

    foreach (['bw_heartbeat', 'bw_deliver_outbox', 'bw_rescan_recent', 'bw_backfill', 'bw_daily_audit', 'bw_payment_attempts', 'bw_checkout_observation', 'bw_synthetic_cleanup'] as $hook) {
        wp_clear_scheduled_hook($hook);

        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions($hook, [], 'business-watchdog');
        }
    }

    delete_option('bw_monitoring_stopped');
    delete_option('bw_outbox_full');
    delete_option('bw_checkout_observation');
    delete_option('bw_checkout_script_altered_at');

    global $wpdb;

    $stateTable = $wpdb->prefix . 'bw_state';

    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($stateTable))) === $stateTable) {
        $wpdb->delete($stateTable, ['name' => 'connection'], ['%s']);
    }

    if (get_option('bw_purge_history_on_uninstall') === 'yes'
        && class_exists('\BusinessWatchdog\WooCommerce\Storage\Schema')) {
        \BusinessWatchdog\WooCommerce\Storage\Schema::drop();
        delete_option('bw_purge_history_on_uninstall');
    }
} catch (\Throwable $exception) {
    error_log('business-watchdog: uninstall_failed');
}
