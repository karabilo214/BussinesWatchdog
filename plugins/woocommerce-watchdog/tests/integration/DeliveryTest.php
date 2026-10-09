<?php

use BusinessWatchdog\WooCommerce\Capture\OrderCapture;
use BusinessWatchdog\WooCommerce\Connection\Connection;
use BusinessWatchdog\WooCommerce\Jobs\BackfillJob;
use BusinessWatchdog\WooCommerce\Jobs\DeliveryJob;
use BusinessWatchdog\WooCommerce\Jobs\EnvironmentEvents;
use BusinessWatchdog\WooCommerce\Jobs\RescanJob;
use BusinessWatchdog\WooCommerce\Storage\Outbox;
use BusinessWatchdog\WooCommerce\Storage\Schema;
use BusinessWatchdog\WooCommerce\Storage\State;

function bw_reset_outbox(): void
{
    global $wpdb;
    $wpdb->query('DELETE FROM ' . Schema::outboxTable());
}

function bw_outbox_rows(): array
{
    global $wpdb;

    return (array) $wpdb->get_results('SELECT * FROM ' . Schema::outboxTable() . ' ORDER BY id', ARRAY_A);
}

function bw_fake_backend(callable $responder): array
{
    $calls = new ArrayObject();
    $filter = static function ($preempt, $args, $url) use ($responder, $calls) {
        if (strpos($url, 'bw.example.invalid') === false) {
            return $preempt;
        }

        $body = json_decode((string) $args['body'], true);
        $calls[] = ['url' => $url, 'headers' => $args['headers'], 'body' => $body, 'raw' => (string) $args['body']];
        [$status, $json, $headers] = array_pad($responder($body, count($calls)), 3, []);

        return [
            'headers' => $headers,
            'body' => (string) wp_json_encode($json),
            'response' => ['code' => $status, 'message' => ''],
            'cookies' => [],
            'filename' => null,
        ];
    };
    add_filter('pre_http_request', $filter, 10, 3);

    return [$calls, $filter];
}

function bw_all_results(array $body, string $status = 'accepted'): array
{
    return array_map(static function ($event, $index) use ($status) {
        return ['index' => $index, 'event_id' => $event['event_id'], 'status' => $status];
    }, $body['events'], array_keys($body['events']));
}

function bw_seed_events(int $count): void
{
    for ($i = 0; $i < $count; $i++) {
        bw_make_order();
    }

    OrderCapture::flush();
}

function bw_reconnect(): void
{
    Connection::store('https://bw.example.invalid', wp_generate_uuid4(), 'bwk_integration_test', base64_encode(random_bytes(32)));
}

bw_test('accepted batch is signed and removed from the outbox', function () {
    bw_reset_outbox();
    bw_seed_events(3);
    [$calls, $filter] = bw_fake_backend(static function ($body) {
        return [202, ['results' => bw_all_results($body)]];
    });
    $result = DeliveryJob::run();
    remove_filter('pre_http_request', $filter, 10);

    bw_assert($result['delivered'] === 3 && bw_outbox_rows() === [], 'events not delivered: ' . wp_json_encode($result));
    $call = $calls[0];
    bw_assert(substr($call['url'], -strlen('/api/v1/ingest/events')) === '/api/v1/ingest/events', 'wrong url');
    bw_assert(isset($call['headers']['X-BW-Signature'], $call['headers']['X-BW-Nonce']) && $call['headers']['X-BW-Signature-Version'] === '1', 'missing signature headers');
    bw_assert(count($call['body']['events']) === 3, 'batch size wrong');
});

bw_test('delivery keeps empty JSON objects as objects', function () {
    bw_reset_outbox();
    $envelope = \BusinessWatchdog\WooCommerce\Capture\EventFactory::envelope('checkout.payment_attempts', 'checkout', '2026-10-09T10:00:00Z', 1, null, [
        'window_start' => '2026-10-09T10:00:00Z',
        'window_end' => '2026-10-09T10:05:00Z',
        'methods' => [['payment_method' => 'bacs', 'failure_classes' => (object) []]],
    ]);
    Outbox::enqueue($envelope, 'payment_attempts:test');
    [$calls, $filter] = bw_fake_backend(static function ($body) {
        return [202, ['results' => bw_all_results($body)]];
    });
    DeliveryJob::run();
    remove_filter('pre_http_request', $filter, 10);

    bw_assert(strpos($calls[0]['raw'], '"failure_classes":{}') !== false, 'empty object re-encoded as a list: ' . $calls[0]['raw']);
});

bw_test('per-event results keep accepted and dead-letter rejected events', function () {
    bw_reset_outbox();
    bw_seed_events(3);
    [, $filter] = bw_fake_backend(static function ($body) {
        $results = bw_all_results($body);
        $results[1]['status'] = 'quarantined';
        $results[1]['code'] = 'schema_invalid';
        $results[2]['status'] = 'duplicate';

        return [207, ['results' => $results]];
    });
    DeliveryJob::run();
    remove_filter('pre_http_request', $filter, 10);

    $rows = bw_outbox_rows();
    bw_assert(count($rows) === 1 && $rows[0]['state'] === Outbox::STATE_DEAD_LETTER && $rows[0]['last_error'] === 'quarantined:schema_invalid', 'unexpected rows: ' . wp_json_encode($rows));
});

bw_test('rate limiting honours Retry-After and server errors back off', function () {
    bw_reset_outbox();
    bw_seed_events(1);
    [, $filter] = bw_fake_backend(static function () {
        return [429, ['code' => 'quota_exceeded'], ['retry-after' => '900']];
    });
    DeliveryJob::run();
    remove_filter('pre_http_request', $filter, 10);

    $row = bw_outbox_rows()[0];
    $delay = strtotime($row['next_attempt_at'] . ' UTC') - time();
    bw_assert($row['state'] === 'pending' && (int) $row['attempts'] === 1 && $delay >= 890, 'retry-after ignored: ' . $delay);

    bw_reset_outbox();
    bw_seed_events(1);
    [, $filter] = bw_fake_backend(static function () {
        return [503, ['code' => 'infrastructure_unavailable']];
    });
    DeliveryJob::run();
    remove_filter('pre_http_request', $filter, 10);

    $row = bw_outbox_rows()[0];
    $delay = strtotime($row['next_attempt_at'] . ' UTC') - time();
    bw_assert($delay >= 20 && $delay <= 40, 'first backoff not ~30s: ' . $delay);
});

bw_test('revoked credentials suspend the connection and keep the backlog', function () {
    bw_reset_outbox();
    bw_seed_events(2);
    [, $filter] = bw_fake_backend(static function () {
        return [401, ['code' => 'credential_revoked']];
    });
    DeliveryJob::run();
    remove_filter('pre_http_request', $filter, 10);

    bw_assert(Connection::current()->status() === Connection::STATUS_SUSPENDED, 'connection not suspended');
    bw_assert(count(bw_outbox_rows()) === 2, 'backlog dropped');
    bw_assert(DeliveryJob::run()['reason'] === 'not_connected', 'suspended connection still delivering');
    bw_reconnect();
});

bw_test('large backlogs are split into batches of at most 100 events', function () {
    bw_reset_outbox();
    bw_seed_events(130);
    [$calls, $filter] = bw_fake_backend(static function ($body) {
        return [202, ['results' => bw_all_results($body)]];
    });
    DeliveryJob::run();
    remove_filter('pre_http_request', $filter, 10);

    bw_assert(count($calls) === 2 && count($calls[0]['body']['events']) === 100 && count($calls[1]['body']['events']) === 30, 'batching wrong');
    bw_assert(bw_outbox_rows() === [], 'backlog not drained');
    bw_assert(count(Outbox::due(100, 2000)) < 100, 'byte limit ignored');
});

bw_test('a concurrent delivery run is skipped while the lock is held', function () {
    bw_assert(State::acquireLock('delivery', 60), 'could not take lock');
    bw_assert(DeliveryJob::run()['reason'] === 'locked', 'lock ignored');
    State::releaseLock('delivery');
});

bw_test('orders missed by hooks are picked up by the rescan', function () {
    Connection::forget();
    $order = bw_make_order();
    OrderCapture::flush();
    bw_assert(bw_events(OrderCapture::orderKey($order->get_id())) === [], 'captured without connection');
    bw_reconnect();

    RescanJob::run();
    bw_assert(count(bw_events(OrderCapture::orderKey($order->get_id()))) === 1, 'rescan did not capture the order');
});

bw_test('backfill covers the 90-day window only', function () {
    Connection::forget();
    $recent = bw_make_order();
    $recent->set_date_created(time() - 30 * 86400);
    $recent->save();
    $old = bw_make_order();
    $old->set_date_created(time() - 120 * 86400);
    $old->save();
    bw_reconnect();

    BackfillJob::start('test');

    for ($i = 0; $i < 50; $i++) {
        $state = BackfillJob::run(10, false);

        if ($state['status'] !== 'running') {
            break;
        }
    }

    bw_assert($state['status'] === 'completed', 'backfill did not complete');
    bw_assert(count(bw_events(OrderCapture::orderKey($recent->get_id()))) === 1, 'recent order not backfilled');
    bw_assert(bw_events(OrderCapture::orderKey($old->get_id())) === [], 'order outside the window was backfilled');
});

bw_test('capabilities and version changes are reported once', function () {
    $first = EnvironmentEvents::capabilities();
    $second = EnvironmentEvents::capabilities();
    bw_assert($second === 0 && ($first === 1 || count(bw_events('integration:capabilities')) >= 1), 'capabilities not deduplicated');
    $event = bw_events('integration:capabilities')[0];
    bw_assert(in_array($event['data']['checkout_mode'], ['classic', 'blocks', 'custom', 'unknown'], true), 'checkout mode not mapped');

    $versions = EnvironmentEvents::currentVersions();
    $versions['woocommerce:woocommerce']['version'] = '5.9.0';
    State::set(EnvironmentEvents::STATE_VERSIONS, $versions);
    bw_assert(EnvironmentEvents::deployments() === 1, 'woocommerce upgrade not reported');
    bw_assert(EnvironmentEvents::deployments() === 0, 'unchanged versions reported again');
});
