<?php

use BusinessWatchdog\WooCommerce\Capture\OrderCapture;
use BusinessWatchdog\WooCommerce\Compat\SchedulerFactory;
use BusinessWatchdog\WooCommerce\Jobs\BackfillJob;
use BusinessWatchdog\WooCommerce\Jobs\HeartbeatJob;
use BusinessWatchdog\WooCommerce\Safety\MonitoringSwitch;
use BusinessWatchdog\WooCommerce\Safety\Guard;
use BusinessWatchdog\WooCommerce\Storage\Outbox;
use BusinessWatchdog\WooCommerce\Storage\State;
use BusinessWatchdog\WooCommerce\Storage\Schema;
use BusinessWatchdog\WooCommerce\Plugin;

function bw_heartbeat_scheduled(): bool
{
    return SchedulerFactory::make()->name() === 'action_scheduler'
        ? as_next_scheduled_action(HeartbeatJob::HOOK, [], 'business-watchdog') !== false
        : wp_next_scheduled(HeartbeatJob::HOOK) !== false;
}

bw_test('a full local queue stops queueing snapshots without touching orders and recovers them from the orders', function () {
    bw_reset_outbox();
    delete_option(Outbox::FULL_OPTION);
    bw_reconnect();
    bw_seed_events(1);
    $limit = count(bw_outbox_rows());

    bw_assert(Outbox::checkLimit($limit, $limit)['full'] === true, 'queue not marked full at the limit');

    $order = bw_make_order(['total' => '42.00']);
    bw_assert(OrderCapture::flush() === 0, 'a snapshot was queued while the queue was full');
    bw_assert(wc_get_order($order->get_id())->get_total() === '42.00', 'the order was not saved');
    bw_assert(count(bw_outbox_rows()) === $limit, 'the queue grew past the limit');
    bw_assert((Outbox::fullState()['skipped'] ?? 0) >= 1, 'skipped changes were not counted');

    bw_reset_outbox();
    $check = Outbox::checkLimit($limit, $limit);
    bw_assert($check['recovered'] === true && ! Outbox::full(), 'queue did not recover after draining');
    bw_assert((State::get(BackfillJob::STATE)['reason'] ?? null) === 'queue_recovery', 'recovery backfill not started');

    for ($i = 0; $i < 50; $i++) {
        if (BackfillJob::run(10, false)['status'] !== 'running') {
            break;
        }
    }

    bw_assert(count(bw_events(OrderCapture::orderKey($order->get_id()))) === 1, 'the skipped order was not recovered');
    bw_assert((State::get('outbox_last_recovery')['skipped'] ?? 0) >= 1, 'recovery was not recorded');
});

bw_test('the emergency stop halts scheduled work and resume restores it', function () {
    do_action('init');
    bw_assert(bw_heartbeat_scheduled(), 'heartbeat not scheduled before the stop');

    MonitoringSwitch::stop('test');
    bw_assert(MonitoringSwitch::stopped() && MonitoringSwitch::stoppedSince() !== null, 'stop not recorded');
    bw_assert(! bw_heartbeat_scheduled(), 'heartbeat still scheduled after the stop');

    MonitoringSwitch::resume();
    bw_assert(! MonitoringSwitch::stopped(), 'stop flag kept after resume');
    bw_assert(bw_heartbeat_scheduled(), 'heartbeat not scheduled after resume');
});

bw_test('a failing monitoring callback leaves WordPress hooks and filter values intact', function () {
    $nextCallbackRan = false;
    $brokenAction = Guard::action(static function (): void {
        throw new RuntimeException('monitoring callback failed');
    }, 'test_action');
    $nextAction = static function () use (&$nextCallbackRan): void {
        $nextCallbackRan = true;
    };
    add_action('bw_guard_test_action', $brokenAction, 10);
    add_action('bw_guard_test_action', $nextAction, 20);
    do_action('bw_guard_test_action');
    remove_action('bw_guard_test_action', $brokenAction, 10);
    remove_action('bw_guard_test_action', $nextAction, 20);
    bw_assert($nextCallbackRan, 'a monitoring exception stopped later WordPress callbacks');

    $brokenFilter = Guard::filter(static function ($value) {
        throw new RuntimeException('monitoring filter failed');
    }, 'test_filter');
    add_filter('bw_guard_test_filter', $brokenFilter);
    $value = apply_filters('bw_guard_test_filter', 'unchanged');
    remove_filter('bw_guard_test_filter', $brokenFilter);
    bw_assert($value === 'unchanged', 'a monitoring exception changed the shop filter value');

    Guard::action(['BusinessWatchdog\\MissingClass', 'missing'], 'missing_callback')();
});

bw_test('an outdated monitoring schema is never upgraded in a storefront request', function () {
    if (is_admin()) {
        return;
    }

    $version = get_option(Schema::OPTION);
    update_option(Schema::OPTION, Schema::VERSION - 1, false);

    try {
        Plugin::boot();
        bw_assert((int) get_option(Schema::OPTION) === Schema::VERSION - 1, 'dbDelta ran during storefront boot');
    } finally {
        update_option(Schema::OPTION, $version, false);
    }
});
