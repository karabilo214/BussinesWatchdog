<?php

namespace BusinessWatchdog\WooCommerce\Compat;

final class Environment
{
    public const ORDER_STORAGE_HPOS = 'hpos';

    public const ORDER_STORAGE_LEGACY = 'legacy';

    public const CHECKOUT_CLASSIC = 'classic';

    public const CHECKOUT_BLOCKS = 'blocks';

    public const CHECKOUT_MIXED = 'mixed';

    public const CHECKOUT_UNKNOWN = 'unknown';

    public static function wooCommerceActive(): bool
    {
        return class_exists('WooCommerce') && function_exists('wc_get_order');
    }

    public static function wooCommerceVersion(): ?string
    {
        if (defined('WC_VERSION')) {
            return (string) WC_VERSION;
        }

        return function_exists('WC') && isset(WC()->version) ? (string) WC()->version : null;
    }

    public static function wooCommerceSupported(): bool
    {
        $version = self::wooCommerceVersion();

        return self::wooCommerceActive() && $version !== null && version_compare($version, BW_MIN_WC, '>=');
    }

    public static function orderStorage(): string
    {
        if (class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')
            && method_exists('\Automattic\WooCommerce\Utilities\OrderUtil', 'custom_orders_table_usage_is_enabled')
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
            return self::ORDER_STORAGE_HPOS;
        }

        return self::ORDER_STORAGE_LEGACY;
    }

    public static function hposSyncEnabled(): bool
    {
        return get_option('woocommerce_custom_orders_table_data_sync_enabled') === 'yes';
    }

    public static function actionSchedulerAvailable(): bool
    {
        return function_exists('as_schedule_recurring_action')
            && function_exists('as_next_scheduled_action')
            && function_exists('as_unschedule_all_actions');
    }

    public static function checkoutMode(): string
    {
        $checkoutPageId = function_exists('wc_get_page_id') ? (int) wc_get_page_id('checkout') : 0;

        if ($checkoutPageId <= 0) {
            return self::CHECKOUT_UNKNOWN;
        }

        $content = (string) get_post_field('post_content', $checkoutPageId);
        $hasBlock = function_exists('has_block') && has_block('woocommerce/checkout', $content);
        $hasShortcode = function_exists('has_shortcode') && has_shortcode($content, 'woocommerce_checkout');

        if ($hasBlock && $hasShortcode) {
            return self::CHECKOUT_MIXED;
        }

        if ($hasBlock) {
            return self::CHECKOUT_BLOCKS;
        }

        if ($hasShortcode) {
            return self::CHECKOUT_CLASSIC;
        }

        return self::CHECKOUT_UNKNOWN;
    }

    /**
     * @return array<string, mixed>
     */
    public static function describe(): array
    {
        global $wp_version;

        return [
            'plugin_version' => BW_PLUGIN_VERSION,
            'php_version' => PHP_VERSION,
            'wordpress_version' => isset($wp_version) ? (string) $wp_version : null,
            'woocommerce_version' => self::wooCommerceVersion(),
            'woocommerce_supported' => self::wooCommerceSupported(),
            'order_storage' => self::wooCommerceActive() ? self::orderStorage() : null,
            'hpos_sync_enabled' => self::wooCommerceActive() ? self::hposSyncEnabled() : null,
            'action_scheduler' => self::actionSchedulerAvailable(),
            'checkout_mode' => self::wooCommerceActive() ? self::checkoutMode() : null,
            'wp_cron_disabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
        ];
    }
}
