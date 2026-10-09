<?php

namespace BusinessWatchdog\WooCommerce\Compat;

final class OrderCacheBuster
{
    public static function forget(int $orderId): void
    {
        if (class_exists('\Automattic\WooCommerce\Caches\OrderCache') && function_exists('wc_get_container')) {
            try {
                $cache = wc_get_container()->get(\Automattic\WooCommerce\Caches\OrderCache::class);

                if (method_exists($cache, 'remove')) {
                    $cache->remove($orderId);
                }
            } catch (\Throwable $ignored) {
            }
        }

        if (class_exists('WC_Cache_Helper')) {
            wp_cache_delete(\WC_Cache_Helper::get_cache_prefix('orders') . 'refunds' . $orderId, 'orders');
        }

        wp_cache_delete($orderId, 'orders');
        clean_post_cache($orderId);
    }
}
