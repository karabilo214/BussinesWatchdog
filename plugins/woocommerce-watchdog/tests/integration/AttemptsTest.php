<?php

use BusinessWatchdog\WooCommerce\Attempts\AttemptHooks;
use BusinessWatchdog\WooCommerce\Attempts\AttemptLog;
use BusinessWatchdog\WooCommerce\Jobs\PaymentAttemptsJob;
use BusinessWatchdog\WooCommerce\Storage\Outbox;
use BusinessWatchdog\WooCommerce\Storage\Schema;

function bw_attempts_reset(): void
{
    global $wpdb;

    $wpdb->query('DELETE FROM ' . Schema::attemptsTable());
    $wpdb->query('DELETE FROM ' . Schema::outboxTable() . " WHERE aggregate_key LIKE 'payment_attempts:%'");
    AttemptHooks::reset();
    $_POST = [];
}

function bw_attempts(): array
{
    global $wpdb;

    return (array) $wpdb->get_results('SELECT * FROM ' . Schema::attemptsTable() . ' ORDER BY id', ARRAY_A);
}

function bw_checkout_order(string $method): WC_Order
{
    $order = wc_create_order();
    $order->set_currency('EUR');
    $order->set_payment_method($method);
    $order->set_total('20.00');
    $order->set_status('pending');
    $order->save();

    return $order;
}

function bw_classic_submit(string $method): void
{
    AttemptHooks::reset();
    $_POST = ['payment_method' => $method];
    do_action('woocommerce_checkout_process');
}

function bw_store_api_request(): WP_REST_Request
{
    $request = new WP_REST_Request('POST', '/wc/store/v1/checkout');
    $request->set_param('payment_method', 'stripe');

    return $request;
}

bw_test('classic checkout records an attempt and its on-hold outcome', function () {
    bw_attempts_reset();
    bw_classic_submit('bacs');
    $order = bw_checkout_order('bacs');
    do_action('woocommerce_checkout_order_processed', $order->get_id(), [], $order);
    $order->update_status('on-hold');
    AttemptHooks::finishRequest();

    $rows = bw_attempts();
    bw_assert(count($rows) === 1, 'expected one attempt, got ' . count($rows));
    bw_assert($rows[0]['payment_method'] === 'bacs' && $rows[0]['outcome'] === 'on_hold', 'wrong attempt ' . wp_json_encode($rows[0]));
    bw_assert((int) $rows[0]['order_id'] === $order->get_id(), 'order id not stored');
});

bw_test('a gateway error after the order is created fails the attempt', function () {
    bw_attempts_reset();
    bw_classic_submit('stripe');
    $order = bw_checkout_order('stripe');
    do_action('woocommerce_checkout_order_processed', $order->get_id(), [], $order);
    apply_filters('woocommerce_add_error', 'Your card was declined.');
    AttemptHooks::finishRequest();

    $rows = bw_attempts();
    bw_assert($rows[0]['outcome'] === 'failed' && $rows[0]['failure_class'] === 'gateway_error', 'not failed: ' . wp_json_encode($rows[0]));
});

bw_test('a validation error without an order is a rejection without customer data', function () {
    bw_attempts_reset();
    bw_classic_submit('stripe');
    apply_filters('woocommerce_add_error', 'Billing Email address is a required field.');
    AttemptHooks::finishRequest();

    $rows = bw_attempts();
    bw_assert(count($rows) === 1 && $rows[0]['outcome'] === 'rejected_before_order', 'no rejection recorded');
    bw_assert($rows[0]['order_id'] === null && $rows[0]['failure_class'] === 'validation', 'rejection details wrong');
});

bw_test('totals refresh and checkouts without errors are not rejections', function () {
    bw_attempts_reset();
    AttemptHooks::reset();
    $_POST = ['payment_method' => 'stripe', 'woocommerce_checkout_update_totals' => '1'];
    do_action('woocommerce_checkout_process');
    apply_filters('woocommerce_add_error', 'Shipping changed.');
    AttemptHooks::finishRequest();

    bw_assert(bw_attempts() === [], 'update totals recorded an attempt');
});

bw_test('a customer retry closes the previous attempt as retried', function () {
    bw_attempts_reset();
    $order = bw_checkout_order('paypal');
    bw_classic_submit('paypal');
    do_action('woocommerce_checkout_order_processed', $order->get_id(), [], $order);
    AttemptHooks::finishRequest();
    bw_classic_submit('paypal');
    do_action('woocommerce_checkout_order_processed', $order->get_id(), [], $order);
    do_action('woocommerce_checkout_order_processed', $order->get_id(), [], $order);
    AttemptHooks::finishRequest();

    $rows = bw_attempts();
    bw_assert(count($rows) === 2, 'expected two attempts, got ' . count($rows));
    bw_assert($rows[0]['outcome'] === 'failed' && $rows[0]['failure_class'] === 'retried', 'first attempt not retried');
    bw_assert($rows[1]['outcome'] === null, 'second attempt should be open');
});

bw_test('store api checkout errors fail the attempt or record a rejection', function () {
    bw_attempts_reset();
    $order = bw_checkout_order('stripe');
    do_action('woocommerce_store_api_checkout_order_processed', $order);
    apply_filters('rest_request_after_callbacks', new WP_Error('woocommerce_rest_payment_error', 'Declined'), [], bw_store_api_request());
    AttemptHooks::finishRequest();

    apply_filters('rest_request_after_callbacks', new WP_REST_Response(['code' => 'woocommerce_rest_invalid_email', 'message' => 'x'], 400), [], bw_store_api_request());
    apply_filters('rest_request_after_callbacks', new WP_REST_Response(['payment_result' => ['payment_status' => 'success']], 200), [], bw_store_api_request());
    AttemptHooks::finishRequest();

    $rows = bw_attempts();
    bw_assert(count($rows) === 2, 'expected two rows, got ' . count($rows));
    bw_assert($rows[0]['outcome'] === 'failed' && $rows[0]['failure_class'] === 'payment_error', 'store api payment error not recorded');
    bw_assert($rows[1]['outcome'] === 'rejected_before_order' && $rows[1]['payment_method'] === 'stripe', 'store api rejection not recorded');
});

bw_test('payment completion pays the attempt and a late payment after timeout is a late success', function () {
    bw_attempts_reset();
    $paid = bw_checkout_order('stripe');
    $slow = bw_checkout_order('paypal');

    foreach ([$paid, $slow] as $order) {
        bw_classic_submit($order->get_payment_method());
        do_action('woocommerce_checkout_order_processed', $order->get_id(), [], $order);
        AttemptHooks::finishRequest();
    }

    $paid->payment_complete('pi_attempt_1');
    PaymentAttemptsJob::run(time() + PaymentAttemptsJob::PENDING_TIMEOUT_SECONDS + 1);

    $rows = bw_attempts();
    bw_assert($rows[0]['outcome'] === 'paid', 'payment_complete not observed: ' . wp_json_encode($rows[0]));
    bw_assert($rows[1]['outcome'] === 'pending_stuck' && $rows[1]['failure_class'] === 'no_result', 'stuck attempt not expired: ' . wp_json_encode($rows[1]));

    wc_get_order($slow->get_id())->payment_complete('PAYID-LATE');
    $late = bw_attempts()[1];
    bw_assert($late['outcome'] === 'late_success' && (int) $late['reported'] === 0, 'late payment not recorded: ' . wp_json_encode($late));
});

bw_test('closed windows are reported once with per-method counters and ordering', function () {
    global $wpdb;

    bw_attempts_reset();
    $base = PaymentAttemptsJob::windowStart(time()) - 2 * PaymentAttemptsJob::WINDOW_SECONDS;
    $insert = static function (string $method, ?string $outcome, string $class, int $offset) use ($wpdb, $base) {
        $at = gmdate('Y-m-d H:i:s', $base + $offset);
        $wpdb->insert(Schema::attemptsTable(), [
            'order_id' => 1000 + $offset,
            'payment_method' => $method,
            'outcome' => $outcome,
            'failure_class' => $class,
            'attempted_at' => $at,
            'resolved_at' => $at,
        ]);
    };
    $insert('stripe', 'failed', 'gateway_error', 10);
    $insert('stripe', 'paid', '', 20);
    $insert('stripe', 'failed', 'status_failed', 30);
    $insert('stripe', 'pending_stuck', 'no_result', 40);
    $insert('bacs', 'on_hold', '', 50);
    $insert('bacs', 'rejected_before_order', 'validation', 60);
    $insert('stripe', 'failed', 'gateway_error', PaymentAttemptsJob::WINDOW_SECONDS + 5);

    $result = PaymentAttemptsJob::run();
    bw_assert($result['windows'] === 2, 'expected two windows, got ' . wp_json_encode($result));

    $events = [];
    foreach ((array) $wpdb->get_col('SELECT payload FROM ' . Schema::outboxTable() . " WHERE aggregate_key LIKE 'payment_attempts:%' ORDER BY id") as $payload) {
        $events[] = json_decode($payload, true);
    }
    bw_assert(count($events) === 2, 'expected two events');
    $first = $events[0];
    bw_assert($first['type'] === 'checkout.payment_attempts' && $first['aggregate_type'] === 'checkout' && $first['aggregate_revision'] === 1, 'wrong envelope');
    bw_assert($first['data']['window_start'] === gmdate('Y-m-d\TH:i:s\Z', $base), 'wrong window start');
    $methods = array_column($first['data']['methods'], null, 'payment_method');
    bw_assert(array_keys($methods) === ['bacs', 'stripe'], 'methods not sorted');
    $stripe = $methods['stripe'];
    bw_assert($stripe['paid'] === 1 && $stripe['failed'] === 2 && $stripe['pending_stuck'] === 1, 'stripe counters wrong ' . wp_json_encode($stripe));
    bw_assert($stripe['trailing_failures'] === 2, 'trailing failures wrong');
    bw_assert($stripe['failure_classes'] === ['gateway_error' => 1, 'no_result' => 1, 'status_failed' => 1], 'failure classes wrong');
    bw_assert($methods['bacs']['on_hold'] === 1 && $methods['bacs']['rejected_before_order'] === 1 && $methods['bacs']['trailing_failures'] === 0, 'bacs counters wrong');

    $raw = (string) $wpdb->get_var('SELECT payload FROM ' . Schema::outboxTable() . " WHERE aggregate_key LIKE 'payment_attempts:%' ORDER BY id LIMIT 1");
    bw_assert(strpos($raw, '"failure_classes":{}') !== false, 'empty failure classes must be a JSON object');
    bw_assert(strpos($raw, '1010') === false, 'order ids leaked into the event');

    bw_assert(PaymentAttemptsJob::run()['windows'] === 0, 'windows reported twice');
});

bw_test('the current window stays open until it closes', function () {
    bw_attempts_reset();
    AttemptLog::reject('stripe', 'validation');

    bw_assert(PaymentAttemptsJob::run()['windows'] === 0, 'open window reported');
    bw_assert(PaymentAttemptsJob::run(time() + PaymentAttemptsJob::WINDOW_SECONDS)['windows'] === 1, 'closed window not reported');
    bw_assert(count(Outbox::pendingFor('payment_attempts:' . gmdate('Y-m-d\TH:i:s\Z', PaymentAttemptsJob::windowStart(time())))) === 1, 'event key wrong');
});
