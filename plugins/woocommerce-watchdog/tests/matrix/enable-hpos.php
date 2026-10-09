<?php
$features = wc_get_container()->get(\Automattic\WooCommerce\Internal\Features\FeaturesController::class);
$features->change_feature_enable('custom_order_tables', true);
$sync = wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class);
if (! $sync->check_orders_table_exists()) {
    $sync->create_database_tables();
}
update_option('woocommerce_custom_orders_table_enabled', 'yes');
echo \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? "hpos-enabled\n" : "hpos-not-enabled\n";
