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

$pageId = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Classic checkout', 'post_name' => 'classic-checkout', 'post_content' => '[woocommerce_checkout]']);
update_option('woocommerce_checkout_page_id', $pageId);
update_option('permalink_structure', '');
flush_rewrite_rules();

echo 'BW_SETUP=' . wp_json_encode(['product_id' => $product->get_id(), 'checkout_page_id' => $pageId]) . PHP_EOL;
