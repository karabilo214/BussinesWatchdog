<?php
/**
 * Plugin Name: Business Watchdog for WooCommerce
 * Plugin URI: https://business-watchdog.com/en
 * Description: Alerts you when a customer paid but the order stayed unpaid, when Stripe or PayPal disagree with WooCommerce, or when payments start failing. Requires a Business Watchdog account: <a href="https://business-watchdog.com/go/wordpress-plugin?placement=plugin_description&amp;lang=en">create one free</a>, then click «Connect to Business Watchdog» on the plugin page.
 * Version: 0.4.2
 * Requires at least: 5.9
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 6.0
 * WC tested up to: 11.2
 * Author: Business Watchdog
 * Author URI: https://business-watchdog.com
 * License: GPL-2.0-or-later
 * Text Domain: business-watchdog
 */

if (! defined('ABSPATH')) {
    exit;
}

define('BUSINESS_WATCHDOG_VERSION', '0.4.2');
define('BUSINESS_WATCHDOG_FILE', __FILE__);
define('BUSINESS_WATCHDOG_DIR', __DIR__);
define('BUSINESS_WATCHDOG_MIN_PHP', '7.4');
define('BUSINESS_WATCHDOG_MIN_WC', '6.0');

if (version_compare(PHP_VERSION, BUSINESS_WATCHDOG_MIN_PHP, '<')) {
    add_action('admin_notices', static function () {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        if ($screen === null || $screen->id !== 'plugins') {
            return;
        }

        echo '<div class="notice notice-error"><p>'
            . esc_html__('Business Watchdog requires PHP 7.4 or newer. The plugin is inactive.', 'business-watchdog')
            . '</p></div>';
    });

    return;
}

if (defined('BUSINESS_WATCHDOG_DISABLED') && BUSINESS_WATCHDOG_DISABLED) {
    add_action('admin_notices', static function () {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        if ($screen === null || $screen->id !== 'plugins') {
            return;
        }

        echo '<div class="notice notice-warning"><p>'
            . esc_html__('Business Watchdog is switched off by BUSINESS_WATCHDOG_DISABLED in wp-config.php. Nothing is recorded or sent; the store works as usual.', 'business-watchdog')
            . '</p></div>';
    });

    return;
}

try {
    require_once __DIR__ . '/src/Autoloader.php';

    if (! class_exists('\BusinessWatchdog\WooCommerce\Autoloader')) {
        return;
    }

    \BusinessWatchdog\WooCommerce\Autoloader::register(__DIR__ . '/src');

    if (! class_exists('\BusinessWatchdog\WooCommerce\Safety\Guard')) {
        return;
    }

    register_activation_hook(__FILE__, \BusinessWatchdog\WooCommerce\Safety\Guard::action(['\BusinessWatchdog\WooCommerce\Plugin', 'activate'], 'activate'));
    register_deactivation_hook(__FILE__, \BusinessWatchdog\WooCommerce\Safety\Guard::action(['\BusinessWatchdog\WooCommerce\Plugin', 'deactivate'], 'deactivate'));

    add_action('before_woocommerce_init', \BusinessWatchdog\WooCommerce\Safety\Guard::action(['\BusinessWatchdog\WooCommerce\Compat\FeatureDeclarations', 'declare'], 'feature_declarations'));
    add_action('plugins_loaded', \BusinessWatchdog\WooCommerce\Safety\Guard::action(['\BusinessWatchdog\WooCommerce\Plugin', 'boot'], 'boot'), 20);
} catch (\Throwable $exception) {
    // A broken monitoring package must not prevent WordPress from loading the storefront.
    error_log('business-watchdog: bootstrap_failed');
}
