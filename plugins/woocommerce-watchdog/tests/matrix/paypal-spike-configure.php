<?php

use WooCommerce\PayPalCommerce\PPCP;

$container = PPCP::container();
$container->get('settings.service.authentication_manager')->authenticate_via_direct_api(true, (string) getenv('BW_PAYPAL_CLIENT_ID'), (string) getenv('BW_PAYPAL_CLIENT_SECRET'));

$onboarding = get_option('woocommerce-ppcp-data-onboarding', []);
update_option('woocommerce-ppcp-data-onboarding', array_merge(is_array($onboarding) ? $onboarding : [], ['completed' => true, 'step' => 6]));

$gateway = get_option('woocommerce_ppcp-gateway_settings', []);
update_option('woocommerce_ppcp-gateway_settings', array_merge(is_array($gateway) ? $gateway : [], ['enabled' => 'yes']));

$settings = get_option('woocommerce-ppcp-data-settings', []);
update_option('woocommerce-ppcp-data-settings', array_merge(is_array($settings) ? $settings : [], array_filter(['authorize_only' => getenv('BW_PAYPAL_AUTHORIZE') === '1', 'invoice_prefix' => getenv('BW_PAYPAL_INVOICE_PREFIX') ?: null], fn ($value) => $value !== null)));

update_option('woocommerce_currency', getenv('BW_PAYPAL_CURRENCY') ?: 'USD');

$gateways = WC()->payment_gateways()->payment_gateways();
$ppcp = $gateways['ppcp-gateway'] ?? null;
echo 'configured paypal ' . (defined('PAYPAL_INTEGRATION_DATE') ? PAYPAL_INTEGRATION_DATE : '') . ' gateway=' . ($ppcp ? ($ppcp->enabled . '/' . ($ppcp->is_available() ? 'available' : 'unavailable')) : 'missing') . PHP_EOL;
