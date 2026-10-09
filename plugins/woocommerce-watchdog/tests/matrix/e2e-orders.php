<?php

use BusinessWatchdog\WooCommerce\Capture\OrderCapture;

$order = wc_create_order();
$order->set_currency('EUR');
$order->set_payment_method('stripe');
$order->set_transaction_id('pi_e2e_' . wp_generate_password(8, false));
$order->set_total('184.00');
$order->set_status('processing');
$order->save();
$order->set_date_paid(time());
$order->save();
OrderCapture::flush();

$refund = wc_create_refund(['order_id' => $order->get_id(), 'amount' => '50.00', 'refund_payment' => false]);
OrderCapture::flush();
$refundId = $refund->get_id();
wc_get_order($refundId)->delete(true);
OrderCapture::flush();
OrderCapture::captureById($order->get_id());

$jpy = wc_create_order();
$jpy->set_currency('JPY');
$jpy->set_payment_method('cod');
$jpy->set_total('1500');
$jpy->set_status('on-hold');
$jpy->save();
OrderCapture::flush();
$jpyId = $jpy->get_id();
$jpy->delete(true);
OrderCapture::flush();

global $wpdb;
$payloads = $wpdb->get_col('SELECT payload FROM ' . \BusinessWatchdog\WooCommerce\Storage\Schema::outboxTable() . ' ORDER BY id');
echo 'BW_EVENTS=' . wp_json_encode(array_map(static function ($payload) {
    return json_decode($payload, true);
}, $payloads)) . PHP_EOL;
