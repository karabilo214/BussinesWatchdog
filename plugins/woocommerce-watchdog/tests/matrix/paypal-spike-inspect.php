<?php

$orderId = (int) getenv('BW_ORDER_ID');
$order = $orderId > 0 ? wc_get_order($orderId) : (wc_get_orders(['limit' => 1, 'orderby' => 'id', 'order' => 'DESC', 'type' => 'shop_order'])[0] ?? null);
if (! $order) { echo "no order\n"; return; }
$describe = function ($value) use (&$describe) {
    if (is_array($value)) { return array_map($describe, $value); }
    $value = (string) $value;
    return preg_match('/^[A-Z0-9]{17}$/', $value) || in_array($value, ['sandbox', 'live', 'CAPTURE', 'AUTHORIZE', 'true', 'false', 'yes', 'no'], true) || is_numeric($value) ? $value : '(' . strlen($value) . ' chars)';
};
$interesting = function (array $meta) use ($describe): array {
    $out = [];
    foreach ($meta as $item) {
        $data = $item->get_data();
        if (preg_match('/ppcp|paypal|transaction|refund|captur|intent|payment/i', (string) $data['key'])) {
            $out[(string) $data['key']] = $describe($data['value']);
        }
    }
    ksort($out);
    return $out;
};
echo 'ORDER ' . $order->get_id() . ' status=' . $order->get_status() . ' method=' . $order->get_payment_method() . ' total=' . $order->get_total() . ' ' . $order->get_currency() . PHP_EOL;
echo 'transaction_id=' . $describe($order->get_transaction_id()) . ' date_paid=' . ($order->get_date_paid() ? 'yes' : 'no') . PHP_EOL;
echo 'order meta: ' . json_encode($interesting($order->get_meta_data()), JSON_UNESCAPED_SLASHES) . PHP_EOL;

if (getenv('BW_SPIKE_REFUND') === '1') {
    $refund = wc_create_refund(['amount' => '3.00', 'reason' => 'Spike partial refund', 'order_id' => $order->get_id(), 'refund_payment' => true]);
    if (is_wp_error($refund)) { echo 'refund error: ' . $refund->get_error_code() . ' ' . $refund->get_error_message() . PHP_EOL; return; }
    $refund = wc_get_order($refund->get_id());
    echo 'REFUND ' . $refund->get_id() . ' amount=' . $refund->get_amount() . ' refunded_payment=' . json_encode($refund->get_refunded_payment()) . PHP_EOL;
    echo 'refund meta: ' . json_encode($interesting($refund->get_meta_data()), JSON_UNESCAPED_SLASHES) . PHP_EOL;
    $order = wc_get_order($order->get_id());
    echo 'order meta after refund: ' . json_encode($interesting($order->get_meta_data()), JSON_UNESCAPED_SLASHES) . PHP_EOL;
}
foreach (array_reverse(wc_get_order_notes(['order_id' => $order->get_id(), 'limit' => 6])) as $note) {
    echo 'note: ' . preg_replace('/\S+@\S+/', '[email]', wp_strip_all_tags($note->content)) . PHP_EOL;
}
