<?php

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__ . '/src/Autoloader.php';

\BusinessWatchdog\WooCommerce\Autoloader::register(__DIR__ . '/src');

foreach (['bw_heartbeat', 'bw_deliver_outbox', 'bw_rescan_recent', 'bw_backfill', 'bw_daily_audit'] as $hook) {
    wp_clear_scheduled_hook($hook);

    if (function_exists('as_unschedule_all_actions')) {
        as_unschedule_all_actions($hook, [], 'business-watchdog');
    }
}

global $wpdb;

$stateTable = $wpdb->prefix . 'bw_state';

if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $stateTable)) === $stateTable) {
    $wpdb->delete($stateTable, ['name' => 'connection'], ['%s']);
}

if (get_option('bw_purge_history_on_uninstall') === 'yes') {
    \BusinessWatchdog\WooCommerce\Storage\Schema::drop();
    delete_option('bw_purge_history_on_uninstall');
}
