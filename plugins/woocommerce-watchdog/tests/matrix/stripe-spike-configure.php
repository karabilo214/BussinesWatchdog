<?php

$settings = get_option('woocommerce_stripe_settings', []);
$settings = array_merge(is_array($settings) ? $settings : [], [
    'enabled' => 'yes',
    'testmode' => 'yes',
    'test_publishable_key' => getenv('BW_STRIPE_PK'),
    'test_secret_key' => getenv('BW_STRIPE_SK'),
    'capture' => 'yes',
]);
update_option('woocommerce_stripe_settings', $settings);
echo 'configured stripe ' . (defined('WC_STRIPE_VERSION') ? WC_STRIPE_VERSION : 'unknown') . PHP_EOL;
