<?php
// Configures the WooCommerce test store (run with `wp eval-file`): Germany/EUR, HPOS, guest checkout, a physical,
// a virtual and a variable product, a German flat-rate shipping zone, bank transfer, Stripe in test mode and
// PayPal Payments in sandbox. Keys come from $bwKeys, prepended by the caller; nothing is printed except a summary.

$features = wc_get_container()->get(\Automattic\WooCommerce\Internal\Features\FeaturesController::class);
$features->change_feature_enable('custom_order_tables', true);
$sync = wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class);
if (! $sync->check_orders_table_exists()) {
    $sync->create_database_tables();
}
update_option('woocommerce_custom_orders_table_enabled', 'yes');

update_option('woocommerce_default_country', 'DE:BE');
update_option('woocommerce_currency', 'EUR');
update_option('woocommerce_price_thousand_sep', '.');
update_option('woocommerce_price_decimal_sep', ',');
update_option('woocommerce_store_address', 'Teststraße 1');
update_option('woocommerce_store_city', 'Berlin');
update_option('woocommerce_store_postcode', '10115');
update_option('woocommerce_enable_guest_checkout', 'yes');
update_option('woocommerce_calc_taxes', 'no');
update_option('woocommerce_coming_soon', 'no');
update_option('woocommerce_store_pages_only', 'no');
update_option('blog_public', '0');
update_option('permalink_structure', '/%postname%/');
WC_Install::create_pages();
flush_rewrite_rules();

$find = function (string $sku): ?int {
    $id = wc_get_product_id_by_sku($sku);

    return $id > 0 ? $id : null;
};

if ($find('BW-COFFEE') === null) {
    $coffee = new WC_Product_Simple();
    $coffee->set_name('Testkaffee 250 g');
    $coffee->set_sku('BW-COFFEE');
    $coffee->set_regular_price('12.90');
    $coffee->set_weight('0.25');
    $coffee->set_manage_stock(true);
    $coffee->set_stock_quantity(500);
    $coffee->set_status('publish');
    $coffee->save();
}

if ($find('BW-VOUCHER') === null) {
    $voucher = new WC_Product_Simple();
    $voucher->set_name('Testgutschein 10 €');
    $voucher->set_sku('BW-VOUCHER');
    $voucher->set_regular_price('10.00');
    $voucher->set_virtual(true);
    $voucher->set_status('publish');
    $voucher->save();
}

if ($find('BW-MUG') === null) {
    $attribute = new WC_Product_Attribute();
    $attribute->set_name('Farbe');
    $attribute->set_options(['Weiß', 'Schwarz']);
    $attribute->set_visible(true);
    $attribute->set_variation(true);

    $mug = new WC_Product_Variable();
    $mug->set_name('Testtasse');
    $mug->set_sku('BW-MUG');
    $mug->set_attributes([$attribute]);
    $mug->set_status('publish');
    $mugId = $mug->save();

    foreach (['Weiß' => '8.50', 'Schwarz' => '9.50'] as $color => $price) {
        $variation = new WC_Product_Variation();
        $variation->set_parent_id($mugId);
        $variation->set_attributes(['farbe' => $color]);
        $variation->set_regular_price($price);
        $variation->set_sku('BW-MUG-'.($color === 'Weiß' ? 'W' : 'S'));
        $variation->set_status('publish');
        $variation->save();
    }

    WC_Product_Variable::sync($mugId);
}

$zones = WC_Shipping_Zones::get_zones();
if (! array_filter($zones, fn (array $zone): bool => $zone['zone_name'] === 'Deutschland')) {
    $zone = new WC_Shipping_Zone();
    $zone->set_zone_name('Deutschland');
    $zone->add_location('DE', 'country');
    $zone->save();
    $instance = $zone->add_shipping_method('flat_rate');
    update_option('woocommerce_flat_rate_'.$instance.'_settings', ['title' => 'Versand', 'tax_status' => 'none', 'cost' => '4.90']);
}

update_option('woocommerce_bacs_settings', ['enabled' => 'yes', 'title' => 'Banküberweisung', 'description' => 'Testzahlung per Überweisung.']);

$stripe = get_option('woocommerce_stripe_settings', []);
update_option('woocommerce_stripe_settings', array_merge(is_array($stripe) ? $stripe : [], [
    'enabled' => 'yes',
    'testmode' => 'yes',
    'test_publishable_key' => $bwKeys['stripe_pk'],
    'test_secret_key' => $bwKeys['stripe_sk'],
    'capture' => 'yes',
]));

$ppcp = \WooCommerce\PayPalCommerce\PPCP::container();
$ppcp->get('settings.service.authentication_manager')->authenticate_via_direct_api(true, $bwKeys['paypal_id'], $bwKeys['paypal_secret']);
$onboarding = get_option('woocommerce-ppcp-data-onboarding', []);
update_option('woocommerce-ppcp-data-onboarding', array_merge(is_array($onboarding) ? $onboarding : [], ['completed' => true, 'step' => 6]));
$gateway = get_option('woocommerce_ppcp-gateway_settings', []);
update_option('woocommerce_ppcp-gateway_settings', array_merge(is_array($gateway) ? $gateway : [], ['enabled' => 'yes']));

$available = array_keys(array_filter(WC()->payment_gateways()->payment_gateways(), fn ($gateway) => $gateway->enabled === 'yes'));
echo 'hpos=' . (\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'on' : 'off')
    . ' currency=' . get_woocommerce_currency()
    . ' products=' . count(wc_get_products(['limit' => -1, 'status' => 'publish', 'return' => 'ids']))
    . ' gateways=' . implode(',', $available)
    . ' checkout=' . get_permalink(wc_get_page_id('checkout')) . PHP_EOL;
