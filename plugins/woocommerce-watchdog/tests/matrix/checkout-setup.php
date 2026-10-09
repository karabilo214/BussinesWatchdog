<?php

$product = new WC_Product_Simple();
$product->set_name('Matrix product');
$product->set_regular_price('10.00');
$product->set_virtual(true);
$product->set_status('publish');
$product->save();

update_option('woocommerce_bacs_settings', ['enabled' => 'yes', 'title' => 'Bank transfer']);
update_option('woocommerce_enable_guest_checkout', 'yes');
update_option('woocommerce_default_country', 'DE');

$originalCheckoutId = (int) get_option('woocommerce_checkout_page_id');
$originalCheckout = $originalCheckoutId > 0 ? get_post($originalCheckoutId) : null;
$blocksCheckoutId = $originalCheckout && strpos((string) $originalCheckout->post_content, 'wp:woocommerce/checkout') !== false ? $originalCheckoutId : 0;

$pageId = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Classic checkout', 'post_name' => 'classic-checkout', 'post_content' => '[woocommerce_checkout]']);
update_option('woocommerce_checkout_page_id', $pageId);
update_option('permalink_structure', '');
flush_rewrite_rules();

echo 'BW_SETUP=' . wp_json_encode([
    'product_id' => $product->get_id(),
    'checkout_page_id' => $pageId,
    'blocks_checkout_page_id' => $blocksCheckoutId,
    'cart_page_id' => (int) get_option('woocommerce_cart_page_id'),
]) . PHP_EOL;
