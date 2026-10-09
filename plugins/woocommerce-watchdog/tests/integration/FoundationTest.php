<?php

use BusinessWatchdog\WooCommerce\Compat\Environment;
use BusinessWatchdog\WooCommerce\Compat\SchedulerFactory;
use BusinessWatchdog\WooCommerce\Jobs\HeartbeatJob;
use BusinessWatchdog\WooCommerce\Security\SecretBox;
use BusinessWatchdog\WooCommerce\Storage\Schema;
use BusinessWatchdog\WooCommerce\Storage\State;

bw_test('woocommerce is detected as supported', function () {
    bw_assert(Environment::wooCommerceSupported(), 'WooCommerce not detected as supported: ' . (string) Environment::wooCommerceVersion());
    $expected = getenv('BW_EXPECT_WC_VERSION');
    bw_assert($expected === false || $expected === '' || Environment::wooCommerceVersion() === $expected, 'Unexpected WooCommerce version ' . Environment::wooCommerceVersion());
});

bw_test('order storage matches the configured mode', function () {
    $expected = getenv('BW_EXPECT_ORDER_STORAGE');
    bw_assert($expected === false || $expected === '' || Environment::orderStorage() === $expected, 'Order storage is ' . Environment::orderStorage());
});

bw_test('local tables exist and schema version is current', function () {
    global $wpdb;

    foreach ([Schema::outboxTable(), Schema::revisionsTable(), Schema::stateTable()] as $table) {
        bw_assert($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table, 'Missing table ' . $table);
    }

    bw_assert(Schema::isCurrent(), 'Schema version not current');
    Schema::install();
    bw_assert(Schema::isCurrent(), 'Re-running dbDelta broke the schema');
});

bw_test('install id is stable', function () {
    $first = State::installId();
    bw_assert(State::installId() === $first, 'Install id changed between reads');
    bw_assert(preg_match('/^[0-9a-f-]{36}$/', $first) === 1, 'Install id is not a UUID');
});

bw_test('heartbeat is scheduled with the available scheduler', function () {
    do_action('init');
    $scheduler = SchedulerFactory::make();

    if ($scheduler->name() === 'action_scheduler') {
        bw_assert(as_next_scheduled_action(HeartbeatJob::HOOK, [], 'business-watchdog') !== false, 'Heartbeat not scheduled in Action Scheduler');
    } else {
        bw_assert(wp_next_scheduled(HeartbeatJob::HOOK) !== false, 'Heartbeat not scheduled in WP-Cron');
    }
});

bw_test('secret box round trip', function () {
    $sealed = SecretBox::seal('top-secret-value');
    bw_assert(SecretBox::open($sealed) === 'top-secret-value', 'Secret box did not round trip');
    bw_assert(strpos(wp_json_encode($sealed), 'top-secret-value') === false || ! SecretBox::available(), 'Sealed secret contains plaintext');
});

bw_test('challenge endpoint returns only the pending challenge', function () {
    $id = wp_generate_uuid4();
    State::set(HeartbeatJob::STATE_PENDING_CHALLENGE, ['id' => $id, 'challenge' => 'bw-test-challenge']);

    $ok = rest_do_request(new WP_REST_Request('GET', '/business-watchdog/v1/challenge/' . $id));
    bw_assert($ok->get_status() === 200 && $ok->get_data() === 'bw-test-challenge', 'Challenge not served: ' . $ok->get_status());

    $wrong = rest_do_request(new WP_REST_Request('GET', '/business-watchdog/v1/challenge/' . wp_generate_uuid4()));
    bw_assert($wrong->get_status() === 404, 'Wrong id did not return 404');

    State::delete(HeartbeatJob::STATE_PENDING_CHALLENGE);
});

bw_test('admin routes require manage_woocommerce', function () {
    wp_set_current_user(0);
    $response = rest_do_request(new WP_REST_Request('GET', '/business-watchdog/v1/diagnostics'));
    bw_assert(in_array($response->get_status(), [401, 403], true), 'Diagnostics open to anonymous users: ' . $response->get_status());
});
