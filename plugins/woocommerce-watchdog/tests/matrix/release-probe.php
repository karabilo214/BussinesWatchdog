<?php

global $wpdb;

$action = getenv('BW_PROBE');
$table = $wpdb->prefix . 'bw_outbox';
$count = static function () use ($wpdb, $table): int {
    return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table ? (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}") : -1;
};
$out = [];

if ($action === 'connect') {
    \BusinessWatchdog\WooCommerce\Connection\Connection::store((string) getenv('BW_PROBE_ENDPOINT'), wp_generate_uuid4(), 'bwk_release_check', base64_encode(random_bytes(32)));
    $out['connected'] = true;
}

if ($action === 'order') {
    $order = wc_create_order();
    $order->set_currency('EUR');
    $order->set_payment_method('bacs');
    $order->set_total('12.00');
    $order->set_status('processing');
    $order->save();

    if (class_exists('\BusinessWatchdog\WooCommerce\Capture\OrderCapture')) {
        \BusinessWatchdog\WooCommerce\Capture\OrderCapture::flush();
    }

    $out['order_saved'] = wc_get_order($order->get_id()) instanceof WC_Order;
}

if ($action === 'uninstall-twice') {
    define('WP_UNINSTALL_PLUGIN', 'business-watchdog/business-watchdog.php');
    include WP_PLUGIN_DIR . '/business-watchdog/uninstall.php';
    include WP_PLUGIN_DIR . '/business-watchdog/uninstall.php';
    $out['uninstalled_twice'] = true;
}

$hook = 'bw_heartbeat';
$out += [
    'version' => defined('BW_PLUGIN_VERSION') ? BW_PLUGIN_VERSION : null,
    'loaded' => class_exists('\BusinessWatchdog\WooCommerce\Plugin', false),
    'schema_current' => class_exists('\BusinessWatchdog\WooCommerce\Storage\Schema') ? \BusinessWatchdog\WooCommerce\Storage\Schema::isCurrent() : null,
    'outbox_rows' => $count(),
    'heartbeat_scheduled' => (function_exists('as_next_scheduled_action') && as_next_scheduled_action($hook, [], 'business-watchdog') !== false) || wp_next_scheduled($hook) !== false,
    'stopped' => is_array(get_option('bw_monitoring_stopped', null)),
    'connection' => $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->prefix . 'bw_state')) === $wpdb->prefix . 'bw_state'
        ? $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bw_state WHERE name = 'connection'") === '1' : null,
];

echo 'BW_PROBE=' . wp_json_encode($out) . PHP_EOL;
