<?php

global $wpdb;

$step = getenv('BW_STEP');
$out = [];
$make = static function (string $via, string $gateway, string $status): int {
    $order = wc_create_order(['created_via' => $via]);
    $order->set_currency('EUR');
    $order->set_payment_method($gateway);
    $order->set_total('10.00');
    $order->set_status('pending');
    $order->save();

    if ($status === 'processing') {
        $order->payment_complete('ch_attempts_' . $order->get_id());
    } elseif ($status !== 'pending') {
        $order->update_status($status);
    }

    return $order->get_id();
};

if ($step === 'key') {
    $consumerKey = 'ck_' . wc_rand_hash();
    $secret = 'cs_' . wc_rand_hash();
    $wpdb->insert($wpdb->prefix . 'woocommerce_api_keys', [
        'user_id' => get_user_by('login', 'admin')->ID,
        'description' => 'Business Watchdog read',
        'permissions' => 'read',
        'consumer_key' => wc_api_hash($consumerKey),
        'consumer_secret' => $secret,
        'truncated_key' => substr($consumerKey, -7),
    ]);
    $out = ['key' => $consumerKey, 'secret' => $secret, 'before' => $make('checkout', 'stripe', 'failed')];
}

if ($step === 'failures') {
    $out = [
        'failed' => [$make('checkout', 'stripe', 'failed'), $make('checkout', 'stripe', 'failed'), $make('store-api', 'stripe', 'failed')],
        'admin' => $make('admin', 'stripe', 'failed'),
        'on_hold' => $make('checkout', 'bacs', 'on-hold'),
    ];
}

if ($step === 'paid') {
    $out = ['paid' => $make('store-api', 'stripe', 'processing')];
}

echo 'BW_STORE=' . wp_json_encode($out) . PHP_EOL;
