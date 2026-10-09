<?php
/**
 * Plugin Name: Business Watchdog for WooCommerce
 * Description: Sends order and refund snapshots to Business Watchdog for payment reconciliation and checkout monitoring.
 * Version: 0.1.0
 * Requires at least: 5.9
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * WC tested up to: 11.2
 * Author: Business Watchdog
 * License: GPL-2.0-or-later
 * Text Domain: business-watchdog
 */

if (! defined('ABSPATH')) {
    exit;
}

define('BW_PLUGIN_VERSION', '0.1.0');
define('BW_PLUGIN_FILE', __FILE__);
define('BW_PLUGIN_DIR', __DIR__);
define('BW_MIN_PHP', '7.4');
define('BW_MIN_WC', '6.0');

if (version_compare(PHP_VERSION, BW_MIN_PHP, '<')) {
    add_action('admin_notices', static function () {
        echo '<div class="notice notice-error"><p>'
            . esc_html__('Business Watchdog requires PHP 7.4 or newer. The plugin is inactive.', 'business-watchdog')
            . '</p></div>';
    });

    return;
}

require_once __DIR__ . '/src/Autoloader.php';

\BusinessWatchdog\WooCommerce\Autoloader::register(__DIR__ . '/src');

register_activation_hook(__FILE__, ['\BusinessWatchdog\WooCommerce\Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['\BusinessWatchdog\WooCommerce\Plugin', 'deactivate']);

add_action('before_woocommerce_init', ['\BusinessWatchdog\WooCommerce\Compat\FeatureDeclarations', 'declare']);
add_action('plugins_loaded', ['\BusinessWatchdog\WooCommerce\Plugin', 'boot'], 20);
