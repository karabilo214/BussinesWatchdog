<?php

$orderId = (int) getenv('BW_ORDER');
sleep(2);
$refund = wc_create_refund(['amount' => '2.00', 'reason' => 'Late', 'order_id' => $orderId, 'refund_payment' => false]);
$order = wc_get_order($orderId);
echo 'LATE refund=' . $refund->get_id() . ' order_modified_gmt=' . gmdate('Y-m-d\TH:i:s', $order->get_date_modified()->getTimestamp()) . PHP_EOL;
