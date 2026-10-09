<?php

namespace BusinessWatchdog\WooCommerce\Compat;

final class FeatureDeclarations
{
    public static function declare(): void
    {
        if (! class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            return;
        }

        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', BW_PLUGIN_FILE, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', BW_PLUGIN_FILE, true);
    }
}
