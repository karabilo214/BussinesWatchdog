<?php

global $wpdb;

$action = getenv('BW_STEP');
$out = [];
$key = static function (string $permissions) use ($wpdb): array {
    $consumerKey = 'ck_' . wc_rand_hash();
    $secret = 'cs_' . wc_rand_hash();
    $wpdb->insert($wpdb->prefix . 'woocommerce_api_keys', [
        'user_id' => get_user_by('login', 'admin')->ID,
        'description' => 'Business Watchdog ' . $permissions,
        'permissions' => $permissions,
        'consumer_key' => wc_api_hash($consumerKey),
        'consumer_secret' => $secret,
        'truncated_key' => substr($consumerKey, -7),
    ]);

    return ['key' => $consumerKey, 'secret' => $secret];
};
$make = static function (array $props): WC_Order {
    $order = wc_create_order();
    $order->set_currency($props['currency'] ?? 'EUR');
    $order->set_payment_method($props['gateway']);
    $order->set_transaction_id($props['transaction_id'] ?? '');
    $order->set_billing_email('buyer@example.test');
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

if ($action === 'setup') {
    $stripe = $make(['gateway' => 'stripe', 'transaction_id' => 'ch_3ChainCharge01', 'total' => '184.00']);
    $refund = wc_create_refund(['amount' => '30.00', 'reason' => 'Chain', 'order_id' => $stripe->get_id(), 'refund_payment' => false]);
    $refund->update_meta_data('_stripe_refund_id', 're_3ChainRefund01');
    $refund->save();
    $paypal = $make(['gateway' => 'ppcp-gateway', 'transaction_id' => '5TR80192ED162870P', 'total' => '10.00', 'currency' => 'USD', 'meta' => ['_ppcp_paypal_payment_mode' => 'sandbox', '_ppcp_paypal_payer_email' => 'payer@example.test']]);
    $yen = $make(['gateway' => 'bacs', 'total' => '1500', 'currency' => 'JPY', 'status' => 'on-hold']);
    $doomed = $make(['gateway' => 'bacs', 'total' => '6.00']);
    $toTrash = $make(['gateway' => 'bacs', 'total' => '5.00']);
    $out = ['read' => $key('read'), 'read_write' => $key('read_write'), 'orders' => ['stripe' => $stripe->get_id(), 'refund' => $refund->get_id(), 'paypal' => $paypal->get_id(), 'yen' => $yen->get_id(), 'doomed' => $doomed->get_id(), 'trash' => $toTrash->get_id()]];
}

if ($action === 'change') {
    $ids = json_decode((string) getenv('BW_ORDERS'), true);
    $late = wc_create_refund(['amount' => '4.00', 'reason' => 'Late', 'order_id' => (int) $ids['stripe'], 'refund_payment' => false]);
    wc_get_order((int) $ids['doomed'])->delete(true);
    wc_get_order((int) $ids['trash'])->delete(false);
    $out = ['late_refund' => $late->get_id()];
}

echo 'BW_STORE=' . wp_json_encode($out) . PHP_EOL;
