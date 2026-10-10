<?php

$orders = wc_get_orders(['limit' => 1, 'orderby' => 'id', 'order' => 'DESC', 'type' => 'shop_order']);
$order = $orders[0] ?? null;
if (! $order) { echo "no order\n"; return; }
$interesting = function (array $meta): array {
    $out = [];
    foreach ($meta as $item) {
        $data = $item->get_data();
        $key = (string) $data['key'];
        $value = is_scalar($data['value']) ? (string) $data['value'] : '[' . gettype($data['value']) . ']';
        if (preg_match('/stripe|transaction|intent|charge|refund|source|customer|payment/i', $key)) {
            $out[$key] = preg_match('/^(pi|ch|re|pm|src|cus|py)_/', $value) || in_array($value, ['yes', 'no'], true) || is_numeric($value) ? $value : '(' . strlen($value) . ' chars)';
        }
    }
    ksort($out);
    return $out;
};
echo 'ORDER ' . $order->get_id() . ' status=' . $order->get_status() . ' method=' . $order->get_payment_method() . ' total=' . $order->get_total() . ' ' . $order->get_currency() . PHP_EOL;
echo 'transaction_id=' . $order->get_transaction_id() . ' date_paid=' . ($order->get_date_paid() ? 'yes' : 'no') . PHP_EOL;
echo 'order meta: ' . json_encode($interesting($order->get_meta_data()), JSON_UNESCAPED_SLASHES) . PHP_EOL;

if (getenv('BW_SPIKE_REFUND') === '1') {
    $refund = wc_create_refund(['amount' => '3.00', 'reason' => 'Spike partial refund', 'order_id' => $order->get_id(), 'refund_payment' => true]);
    if (is_wp_error($refund)) { echo 'refund error: ' . $refund->get_error_code() . PHP_EOL; return; }
    $refund = wc_get_order($refund->get_id());
    echo 'REFUND ' . $refund->get_id() . ' amount=' . $refund->get_amount() . ' refunded_payment=' . json_encode($refund->get_refunded_payment()) . PHP_EOL;
    echo 'refund meta: ' . json_encode($interesting($refund->get_meta_data()), JSON_UNESCAPED_SLASHES) . PHP_EOL;
    $order = wc_get_order($order->get_id());
    echo 'order meta after refund: ' . json_encode($interesting($order->get_meta_data()), JSON_UNESCAPED_SLASHES) . PHP_EOL;
    foreach (wc_get_order_notes(['order_id' => $order->get_id(), 'limit' => 5]) as $note) {
        echo 'note: ' . preg_replace('/\b(?!(re|ch|pi)_)\S*@\S+/', '[email]', wp_strip_all_tags($note->content)) . PHP_EOL;
    }
}
