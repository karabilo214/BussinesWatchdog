<?php

namespace BusinessWatchdog\WooCommerce;

use BusinessWatchdog\WooCommerce\Admin\SettingsPage;
use BusinessWatchdog\WooCommerce\Attempts\AttemptHooks;
use BusinessWatchdog\WooCommerce\Capture\OrderCapture;
use BusinessWatchdog\WooCommerce\Capture\OrderHooks;
use BusinessWatchdog\WooCommerce\Compat\Environment;
use BusinessWatchdog\WooCommerce\Compat\SchedulerFactory;
use BusinessWatchdog\WooCommerce\Compat\WpCronAdapter;
use BusinessWatchdog\WooCommerce\Jobs\BackfillJob;
use BusinessWatchdog\WooCommerce\Jobs\DeliveryJob;
use BusinessWatchdog\WooCommerce\Jobs\EnvironmentEvents;
use BusinessWatchdog\WooCommerce\Jobs\HeartbeatJob;
use BusinessWatchdog\WooCommerce\Jobs\ObservationReportJob;
use BusinessWatchdog\WooCommerce\Observation\CheckoutObservation;
use BusinessWatchdog\WooCommerce\Jobs\PaymentAttemptsJob;
use BusinessWatchdog\WooCommerce\Jobs\RescanJob;
use BusinessWatchdog\WooCommerce\Rest\RestController;
use BusinessWatchdog\WooCommerce\Safety\MonitoringSwitch;
use BusinessWatchdog\WooCommerce\Safety\Guard;
use BusinessWatchdog\WooCommerce\Storage\Schema;
use BusinessWatchdog\WooCommerce\Synthetic\SyntheticOrders;
use BusinessWatchdog\WooCommerce\Storage\State;

final class Plugin
{
    public const RECURRING = [
        HeartbeatJob::HOOK => HeartbeatJob::INTERVAL_SECONDS,
        DeliveryJob::HOOK => DeliveryJob::INTERVAL_SECONDS,
        RescanJob::HOOK => RescanJob::INTERVAL_SECONDS,
        BackfillJob::HOOK => BackfillJob::INTERVAL_SECONDS,
        BackfillJob::AUDIT_HOOK => BackfillJob::AUDIT_INTERVAL_SECONDS,
        PaymentAttemptsJob::HOOK => PaymentAttemptsJob::INTERVAL_SECONDS,
        ObservationReportJob::HOOK => ObservationReportJob::INTERVAL_SECONDS,
        SyntheticOrders::CLEANUP_HOOK => SyntheticOrders::CLEANUP_INTERVAL_SECONDS,
    ];

    public static function activate(): void
    {
        Schema::install();
        State::installId();
    }

    public static function deactivate(): void
    {
        self::clearSchedules();
    }

    public static function clearSchedules(): void
    {
        foreach (array_keys(self::RECURRING) as $hook) {
            SchedulerFactory::make()->clear($hook);
            wp_clear_scheduled_hook($hook);
        }
    }

    public static function boot(): void
    {
        add_filter('cron_schedules', Guard::filter([WpCronAdapter::class, 'registerSchedules'], 'cron_schedules'));

        if (MonitoringSwitch::stopped()) {
            add_action('admin_notices', Guard::action([self::class, 'stoppedNotice'], 'stopped_notice'));

            if (is_admin()) {
                SettingsPage::register();
            }

            self::registerCli();

            return;
        }

        if (! Environment::wooCommerceSupported()) {
            add_action('admin_notices', Guard::action([self::class, 'unsupportedNotice'], 'unsupported_notice'));

            return;
        }

        if (! Schema::isCurrent()) {
            // Never run dbDelta in a shopper's request, especially during checkout.
            if (! is_admin()
                || (function_exists('wp_doing_ajax') && wp_doing_ajax())
                || (function_exists('wp_doing_cron') && wp_doing_cron())) {
                return;
            }

            try {
                Schema::install();
            } catch (\Throwable $exception) {
                add_action('admin_notices', Guard::action([self::class, 'schemaNotice'], 'schema_notice'));

                return;
            }

            if (! Schema::isCurrent()) {
                add_action('admin_notices', Guard::action([self::class, 'schemaNotice'], 'schema_notice'));

                return;
            }
        }

        OrderHooks::register();
        AttemptHooks::register();
        CheckoutObservation::register();
        SyntheticOrders::register();
        OrderCapture::onRecorded([self::class, 'scheduleDelivery']);
        add_action(HeartbeatJob::HOOK, Guard::action([self::class, 'heartbeat'], 'heartbeat'));
        add_action(DeliveryJob::HOOK, Guard::action([DeliveryJob::class, 'run'], 'delivery'));
        add_action(RescanJob::HOOK, Guard::action([RescanJob::class, 'run'], 'rescan'));
        add_action(BackfillJob::HOOK, Guard::action([BackfillJob::class, 'run'], 'backfill'));
        add_action(BackfillJob::AUDIT_HOOK, Guard::action([BackfillJob::class, 'dailyAudit'], 'backfill_audit'));
        add_action(PaymentAttemptsJob::HOOK, Guard::action([self::class, 'reportPaymentAttempts'], 'payment_attempt_report'));
        add_action(ObservationReportJob::HOOK, Guard::action([self::class, 'reportCheckoutObservation'], 'observation_report'));
        add_action(SyntheticOrders::CLEANUP_HOOK, Guard::action([SyntheticOrders::class, 'cleanup'], 'synthetic_cleanup'), 10, 0);
        add_action('upgrader_process_complete', Guard::action([self::class, 'afterUpgrade'], 'after_upgrade'), 20, 0);
        add_action('rest_api_init', Guard::action([RestController::class, 'register'], 'rest_register'));
        add_action('init', Guard::action([self::class, 'ensureSchedules'], 'ensure_schedules'));

        if (is_admin()) {
            SettingsPage::register();
        }

        self::registerCli();
    }

    private static function registerCli(): void
    {
        if (! defined('WP_CLI') || ! WP_CLI) {
            return;
        }

        $command = new Cli\Command();
        \WP_CLI::add_command('business-watchdog stop', [$command, 'stop'], ['shortdesc' => 'Stop all monitoring work on this site (orders and checkout are not affected).']);
        \WP_CLI::add_command('business-watchdog resume', [$command, 'resume'], ['shortdesc' => 'Resume monitoring after a stop.']);
        \WP_CLI::add_command('business-watchdog diagnostics', [$command, 'diagnostics'], ['shortdesc' => 'Print diagnostics as JSON.']);

        if (MonitoringSwitch::stopped()) {
            return;
        }

        \WP_CLI::add_command('business-watchdog pair', [$command, 'pair'], [
            'shortdesc' => 'Pair this store with Business Watchdog.',
            'synopsis' => [
                ['type' => 'assoc', 'name' => 'endpoint', 'optional' => false],
                ['type' => 'assoc', 'name' => 'code', 'optional' => false],
            ],
        ]);
        \WP_CLI::add_command('business-watchdog heartbeat', [$command, 'heartbeat'], ['shortdesc' => 'Send a heartbeat now.']);
        \WP_CLI::add_command('business-watchdog deliver', [$command, 'deliver'], ['shortdesc' => 'Deliver pending outbox events now.']);
        \WP_CLI::add_command('business-watchdog payment-attempts', [$command, 'paymentAttempts'], [
            'shortdesc' => 'Resolve open payment attempts and report closed windows now.',
            'synopsis' => [
                ['type' => 'assoc', 'name' => 'advance', 'optional' => true, 'description' => 'Evaluate as if this many seconds had passed (closes windows, expires pending attempts).'],
            ],
        ]);
        \WP_CLI::add_command('business-watchdog checkout-observation', [$command, 'checkoutObservation'], [
            'shortdesc' => 'Report closed checkout observation windows now.',
            'synopsis' => [
                ['type' => 'assoc', 'name' => 'advance', 'optional' => true, 'description' => 'Evaluate as if this many seconds had passed (closes windows).'],
            ],
        ]);
        \WP_CLI::add_command('business-watchdog cleanup-synthetic', [$command, 'cleanupSynthetic'], [
            'shortdesc' => 'Delete checkout drafts created by verified browser checks.',
            'synopsis' => [
                ['type' => 'assoc', 'name' => 'advance', 'optional' => true, 'description' => 'Evaluate as if this many seconds had passed.'],
            ],
        ]);
        \WP_CLI::add_command('business-watchdog rescan', [$command, 'rescan'], ['shortdesc' => 'Rescan orders changed in the last 48 hours.']);
        \WP_CLI::add_command('business-watchdog backfill', [$command, 'backfill'], [
            'shortdesc' => 'Run backfill pages (90-day window).',
            'synopsis' => [
                ['type' => 'flag', 'name' => 'start', 'optional' => true],
                ['type' => 'assoc', 'name' => 'pages', 'optional' => true],
            ],
        ]);
    }

    public static function ensureSchedules(): void
    {
        $scheduler = SchedulerFactory::make();

        foreach (self::RECURRING as $hook => $interval) {
            $scheduler->ensureRecurring($hook, $interval);
        }
    }

    public static function heartbeat(): void
    {
        EnvironmentEvents::capabilities();
        EnvironmentEvents::deployments();
        HeartbeatJob::run();
    }

    public static function reportPaymentAttempts(): void
    {
        if (PaymentAttemptsJob::run()['windows'] > 0) {
            self::scheduleDelivery();
        }
    }

    public static function reportCheckoutObservation(): void
    {
        if (ObservationReportJob::run() > 0) {
            self::scheduleDelivery();
        }
    }

    public static function afterUpgrade(): void
    {
        EnvironmentEvents::deployments('explicit_hook');
    }

    public static function scheduleDelivery(): void
    {
        SchedulerFactory::make()->enqueueAsync(DeliveryJob::HOOK);
    }

    public static function onPaired(): void
    {
        BackfillJob::start('pairing');
        EnvironmentEvents::capabilities();
        EnvironmentEvents::deployments();
    }

    public static function stoppedNotice(): void
    {
        echo '<div class="notice notice-warning"><p>' . esc_html__('Business Watchdog monitoring is stopped on this site. Orders and checkout work as usual; nothing is recorded or sent until monitoring is resumed.', 'business-watchdog') . '</p></div>';
    }

    public static function unsupportedNotice(): void
    {
        $version = Environment::wooCommerceVersion();
        $message = $version === null
            ? __('Business Watchdog requires WooCommerce to be installed and active.', 'business-watchdog')
            : sprintf(__('Business Watchdog requires WooCommerce %1$s or newer (found %2$s).', 'business-watchdog'), BW_MIN_WC, $version);

        echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
    }

    public static function schemaNotice(): void
    {
        echo '<div class="notice notice-error"><p>' . esc_html__('Business Watchdog could not prepare its own tables. Monitoring is inactive; orders and checkout remain available.', 'business-watchdog') . '</p></div>';
    }
}
