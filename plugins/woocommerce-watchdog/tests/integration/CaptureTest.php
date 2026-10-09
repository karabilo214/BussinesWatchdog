<?php

use BusinessWatchdog\WooCommerce\Capture\OrderCapture;
use BusinessWatchdog\WooCommerce\Money\MinorUnits;
use BusinessWatchdog\WooCommerce\Storage\Outbox;
use BusinessWatchdog\WooCommerce\Storage\State;

function bw_make_order(array $props = []): WC_Order
{
    $order = wc_create_order();
    $order->set_currency($props['currency'] ?? 'EUR');
    $order->set_payment_method($props['gateway'] ?? 'stripe');
    $order->set_transaction_id($props['transaction_id'] ?? 'pi_test_' . wp_generate_password(8, false));
    $order->set_total($props['total'] ?? '184.00');
    $order->set_status($props['status'] ?? 'processing');
    $order->save();

    return $order;
}

function bw_events(string $aggregateKey): array
{
    return array_map(static function (array $row): array {
        return json_decode($row['payload'], true);
    }, Outbox::pendingFor($aggregateKey));
}

bw_test('minor units conversion uses strings and rejects lossy amounts', function () {
    bw_assert(MinorUnits::fromDecimal('184.00', 2) === '18400', '184.00');
    bw_assert(MinorUnits::fromDecimal('184', 2) === '18400', '184');
    bw_assert(MinorUnits::fromDecimal('0.5', 2) === '50', '0.5');
    bw_assert(MinorUnits::fromDecimal('1500', 0) === '1500', 'JPY');
    bw_assert(MinorUnits::fromDecimal('10.005', 2) === null, 'lossy amount accepted');
    bw_assert(MinorUnits::fromDecimal('10.0050', 3) === '10005', 'KWD');
    bw_assert(MinorUnits::fromDecimal(19.99, 2) === '1999', 'float input');
    bw_assert(MinorUnits::fromDecimal('-5', 2) === null, 'negative accepted');
});

bw_test('saving an order records one order snapshot with minor units', function () {
    $order = bw_make_order(['transaction_id' => 'pi_capture_1']);
    bw_assert(in_array($order->get_id(), OrderCapture::pendingIds(), true), 'order hooks did not mark the order dirty');
    OrderCapture::flush();

    $events = bw_events(OrderCapture::orderKey($order->get_id()));
    bw_assert(count($events) === 1, 'expected 1 event, got ' . count($events));
    $event = $events[0];
    bw_assert($event['type'] === 'order.snapshot' && $event['aggregate_id'] === (string) $order->get_id(), 'wrong envelope');
    bw_assert($event['aggregate_revision'] === 1, 'revision is not 1');
    bw_assert($event['data']['total_minor'] === '18400' && $event['data']['currency_exponent'] === 2, 'wrong amount');
    bw_assert($event['data']['gateway'] === 'stripe' && $event['data']['financial_support'] === 'supported', 'gateway support wrong');
    bw_assert($event['data']['transaction_ref'] === 'pi_capture_1', 'transaction ref missing');
    bw_assert($event['data']['status'] === 'processing', 'status wrong');
    bw_assert(isset($event['data']['display_number']), 'display number missing');
});

bw_test('rescanning an unchanged order does not create a new revision', function () {
    $order = bw_make_order();
    OrderCapture::flush();
    OrderCapture::captureById($order->get_id());
    OrderCapture::captureById($order->get_id());

    bw_assert(count(bw_events(OrderCapture::orderKey($order->get_id()))) === 1, 'unchanged rescan created events');
});

bw_test('a status change creates the next revision', function () {
    $order = bw_make_order();
    OrderCapture::flush();
    $order->update_status('completed');
    OrderCapture::flush();

    $events = bw_events(OrderCapture::orderKey($order->get_id()));
    $last = end($events);
    bw_assert(count($events) === 2 && $last['aggregate_revision'] === 2 && $last['data']['status'] === 'completed', 'status change not captured');
});

bw_test('refunds are captured with their parent order id', function () {
    $order = bw_make_order();
    OrderCapture::flush();
    $refund = wc_create_refund(['order_id' => $order->get_id(), 'amount' => '50.00', 'reason' => 'test', 'refund_payment' => false]);
    bw_assert($refund instanceof WC_Order_Refund, 'refund not created');
    OrderCapture::flush();

    $events = bw_events(OrderCapture::refundKey($refund->get_id()));
    bw_assert(count($events) === 1, 'expected 1 refund event, got ' . count($events));
    $data = $events[0]['data'];
    bw_assert($data['order_id'] === (string) $order->get_id() && $data['amount_minor'] === '5000', 'refund data wrong');
    bw_assert($data['external_required'] === false && $data['status'] === 'recorded', 'refund flags wrong');
});

bw_test('deleting a refund emits a deleted refund snapshot', function () {
    $order = bw_make_order();
    $refund = wc_create_refund(['order_id' => $order->get_id(), 'amount' => '20.00', 'refund_payment' => false]);
    OrderCapture::flush();
    $refundId = $refund->get_id();
    wc_get_order($refundId)->delete(true);
    OrderCapture::markDirty($order->get_id());
    OrderCapture::flush();

    $events = bw_events(OrderCapture::refundKey($refundId));
    $last = end($events);
    bw_assert(count($events) === 2 && $last['data']['status'] === 'deleted' && $last['data']['amount_minor'] === '2000', 'deleted refund not captured');
});

bw_test('refund deletion is detected by hooks where WooCommerce fires them, otherwise by the next rescan', function () {
    $order = bw_make_order();
    $refund = wc_create_refund(['order_id' => $order->get_id(), 'amount' => '10.00', 'refund_payment' => false]);
    OrderCapture::flush();
    $refundId = $refund->get_id();
    wc_get_order($refundId)->delete(true);
    $markedByHook = in_array($order->get_id(), OrderCapture::pendingIds(), true);
    OrderCapture::flush();

    if (! $markedByHook) {
        OrderCapture::captureById($order->get_id());
    }

    $events = bw_events(OrderCapture::refundKey($refundId));
    $last = end($events);
    bw_assert($last['data']['status'] === 'deleted', 'deleted refund not detected (hook fired: ' . ($markedByHook ? 'yes' : 'no') . ')');
    $GLOBALS['bwObservations']['refund_delete_hook'] = $markedByHook;
});

bw_test('deleting an order emits order.deleted', function () {
    $order = bw_make_order();
    OrderCapture::flush();
    $id = $order->get_id();
    $order->delete(true);
    OrderCapture::flush();

    $events = bw_events(OrderCapture::orderKey($id));
    $last = end($events);
    bw_assert($last['type'] === 'order.deleted' && $last['data']['reason_code'] === 'deleted' && $last['aggregate_revision'] === 2, 'order deletion not captured');
});

bw_test('trashing an order records the trash status', function () {
    $order = bw_make_order();
    OrderCapture::flush();
    $id = $order->get_id();
    $order->delete(false);
    OrderCapture::flush();

    $events = bw_events(OrderCapture::orderKey($id));
    $last = end($events);
    bw_assert($last['type'] === 'order.snapshot' && $last['data']['status'] === 'trash', 'trash not captured: ' . ($last['data']['status'] ?? $last['type']));
});

bw_test('zero-decimal currencies and unsupported gateways', function () {
    $order = bw_make_order(['currency' => 'JPY', 'total' => '1500', 'gateway' => 'cod']);
    OrderCapture::flush();
    $data = bw_events(OrderCapture::orderKey($order->get_id()))[0]['data'];
    bw_assert($data['total_minor'] === '1500' && $data['currency_exponent'] === 0, 'JPY amount wrong');
    bw_assert($data['financial_support'] === 'unsupported', 'cod should be unsupported');
});

bw_test('lossy amounts are not sent and are reported', function () {
    State::delete(OrderCapture::STATE_LAST_ERROR);
    $order = bw_make_order(['total' => '10.005']);
    OrderCapture::flush();

    if ((string) wc_get_order($order->get_id())->get_total('edit') !== '10.005') {
        return;
    }

    bw_assert(bw_events(OrderCapture::orderKey($order->get_id())) === [], 'lossy amount was sent');
    $error = State::get(OrderCapture::STATE_LAST_ERROR);
    bw_assert(is_array($error) && $error['code'] === 'amount_not_representable', 'error not recorded');
});

bw_test('blocks checkout drafts are not sent', function () {
    if (! array_key_exists('wc-checkout-draft', wc_get_order_statuses())) {
        return;
    }

    $order = bw_make_order(['status' => 'checkout-draft']);
    OrderCapture::flush();
    bw_assert(bw_events(OrderCapture::orderKey($order->get_id())) === [], 'checkout draft was sent');
});
