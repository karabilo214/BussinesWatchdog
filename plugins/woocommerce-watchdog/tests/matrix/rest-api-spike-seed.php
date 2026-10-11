<?php

global $wpdb;

$user = get_user_by('login', 'admin');
$key = 'ck_' . wc_rand_hash();
$secret = 'cs_' . wc_rand_hash();
$wpdb->insert($wpdb->prefix . 'woocommerce_api_keys', [
    'user_id' => $user->ID,
    'description' => 'Business Watchdog (read)',
    'permissions' => 'read',
    'consumer_key' => wc_api_hash($key),
    'consumer_secret' => $secret,
    'truncated_key' => substr($key, -7),
]);

$writeKey = 'ck_' . wc_rand_hash();
$writeSecret = 'cs_' . wc_rand_hash();
$wpdb->insert($wpdb->prefix . 'woocommerce_api_keys', [
    'user_id' => $user->ID,
    'description' => 'Spike read_write',
    'permissions' => 'read_write',
    'consumer_key' => wc_api_hash($writeKey),
    'consumer_secret' => $writeSecret,
    'truncated_key' => substr($writeKey, -7),
]);

$make = static function (array $props): WC_Order {
    $order = wc_create_order();
    $order->set_currency($props['currency'] ?? 'EUR');
    $order->set_payment_method($props['gateway']);
    $order->set_payment_method_title($props['title'] ?? $props['gateway']);
    $order->set_transaction_id($props['transaction_id'] ?? '');
    $order->set_billing_email('buyer@example.test');
    $order->set_billing_first_name('Private');
    $order->set_total($props['total']);
    $order->set_status($props['status'] ?? 'processing');

    foreach ($props['meta'] ?? [] as $metaKey => $value) {
        $order->update_meta_data($metaKey, $value);
    }

    if (($props['status'] ?? 'processing') === 'processing') {
        $order->set_date_paid(time());
    }

    $order->save();

    return $order;
};

$stripe = $make(['gateway' => 'stripe', 'title' => 'Card', 'transaction_id' => 'ch_3SpikeCharge0001', 'total' => '184.00', 'meta' => ['_stripe_intent_id' => 'pi_3SpikeIntent0001', '_stripe_currency' => 'EUR', '_bw_payment_mode' => 'test']]);
$refund = wc_create_refund(['amount' => '30.00', 'reason' => 'Spike', 'order_id' => $stripe->get_id(), 'refund_payment' => false]);
$refund->update_meta_data('_stripe_refund_id', 're_3SpikeRefund0001');
$refund->save();
$paypal = $make(['gateway' => 'ppcp-gateway', 'title' => 'PayPal', 'transaction_id' => '5TR80192ED162870P', 'total' => '10.00', 'currency' => 'USD', 'meta' => ['_ppcp_paypal_order_id' => '56072027MF355241N', '_ppcp_paypal_payment_mode' => 'sandbox', 'custom_visible_key' => 'visible']]);
$yen = $make(['gateway' => 'bacs', 'total' => '1500', 'currency' => 'JPY', 'status' => 'on-hold']);
$odd = $make(['gateway' => 'stripe', 'transaction_id' => 'ch_3SpikeCharge0002', 'total' => '19.999']);
$trashed = $make(['gateway' => 'bacs', 'total' => '5.00']);
$trashed->delete(false);
$deleted = $make(['gateway' => 'bacs', 'total' => '6.00']);
$deletedId = $deleted->get_id();
$deleted->delete(true);

for ($i = 0; $i < (int) (getenv('BW_SPIKE_BULK') ?: 0); $i++) {
    $make(['gateway' => 'bacs', 'total' => '1.00', 'status' => 'completed']);
}

echo 'BW_SPIKE=' . wp_json_encode([
    'key' => $key,
    'write_key' => $writeKey,
    'write_secret' => $writeSecret,
    'secret' => $secret,
    'stripe' => $stripe->get_id(),
    'refund' => $refund->get_id(),
    'paypal' => $paypal->get_id(),
    'yen' => $yen->get_id(),
    'odd' => $odd->get_id(),
    'trashed' => $trashed->get_id(),
    'deleted' => $deletedId,
    'decimals' => get_option('woocommerce_price_num_decimals'),
]) . PHP_EOL;
