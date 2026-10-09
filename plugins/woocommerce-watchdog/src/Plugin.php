<?php

namespace BusinessWatchdog\WooCommerce;

use BusinessWatchdog\WooCommerce\Admin\SettingsPage;
use BusinessWatchdog\WooCommerce\Capture\OrderCapture;
use BusinessWatchdog\WooCommerce\Capture\OrderHooks;
use BusinessWatchdog\WooCommerce\Compat\Environment;
use BusinessWatchdog\WooCommerce\Compat\SchedulerFactory;
use BusinessWatchdog\WooCommerce\Compat\WpCronAdapter;
use BusinessWatchdog\WooCommerce\Jobs\BackfillJob;
use BusinessWatchdog\WooCommerce\Jobs\DeliveryJob;
use BusinessWatchdog\WooCommerce\Jobs\EnvironmentEvents;
use BusinessWatchdog\WooCommerce\Jobs\HeartbeatJob;
use BusinessWatchdog\WooCommerce\Jobs\RescanJob;
use BusinessWatchdog\WooCommerce\Rest\RestController;
use BusinessWatchdog\WooCommerce\Storage\Schema;
use BusinessWatchdog\WooCommerce\Storage\State;

final class Plugin
{
    public const RECURRING = [
        HeartbeatJob::HOOK => HeartbeatJob::INTERVAL_SECONDS,
        DeliveryJob::HOOK => DeliveryJob::INTERVAL_SECONDS,
        RescanJob::HOOK => RescanJob::INTERVAL_SECONDS,
        BackfillJob::HOOK => BackfillJob::INTERVAL_SECONDS,
        BackfillJob::AUDIT_HOOK => BackfillJob::AUDIT_INTERVAL_SECONDS,
    ];

    public static function activate(): void
    {
        Schema::install();
        State::installId();
    }

    public static function deactivate(): void
    {
        foreach (array_keys(self::RECURRING) as $hook) {
            SchedulerFactory::make()->clear($hook);
            wp_clear_scheduled_hook($hook);
        }
    }

    public static function boot(): void
    {
        add_filter('cron_schedules', [WpCronAdapter::class, 'registerSchedules']);

        if (! Environment::wooCommerceSupported()) {
            add_action('admin_notices', [self::class, 'unsupportedNotice']);

            return;
        }

        if (! Schema::isCurrent()) {
            Schema::install();
        }

        OrderHooks::register();
        OrderCapture::onRecorded([self::class, 'scheduleDelivery']);
        add_action(HeartbeatJob::HOOK, [self::class, 'heartbeat']);
        add_action(DeliveryJob::HOOK, [DeliveryJob::class, 'run']);
        add_action(RescanJob::HOOK, [RescanJob::class, 'run']);
        add_action(BackfillJob::HOOK, [BackfillJob::class, 'run']);
        add_action(BackfillJob::AUDIT_HOOK, [BackfillJob::class, 'dailyAudit']);
        add_action('upgrader_process_complete', [self::class, 'afterUpgrade'], 20, 0);
        add_action('rest_api_init', [RestController::class, 'register']);
        add_action('init', [self::class, 'ensureSchedules']);

        if (is_admin()) {
            SettingsPage::register();
        }

        if (defined('WP_CLI') && WP_CLI) {
            $command = new Cli\Command();
            \WP_CLI::add_command('business-watchdog pair', [$command, 'pair'], [
                'shortdesc' => 'Pair this store with Business Watchdog.',
                'synopsis' => [
                    ['type' => 'assoc', 'name' => 'endpoint', 'optional' => false],
                    ['type' => 'assoc', 'name' => 'code', 'optional' => false],
                ],
            ]);
            \WP_CLI::add_command('business-watchdog heartbeat', [$command, 'heartbeat'], ['shortdesc' => 'Send a heartbeat now.']);
            \WP_CLI::add_command('business-watchdog diagnostics', [$command, 'diagnostics'], ['shortdesc' => 'Print diagnostics as JSON.']);
            \WP_CLI::add_command('business-watchdog deliver', [$command, 'deliver'], ['shortdesc' => 'Deliver pending outbox events now.']);
            \WP_CLI::add_command('business-watchdog rescan', [$command, 'rescan'], ['shortdesc' => 'Rescan orders changed in the last 48 hours.']);
            \WP_CLI::add_command('business-watchdog backfill', [$command, 'backfill'], [
                'shortdesc' => 'Run backfill pages (90-day window).',
                'synopsis' => [
                    ['type' => 'flag', 'name' => 'start', 'optional' => true],
                    ['type' => 'assoc', 'name' => 'pages', 'optional' => true],
                ],
            ]);
        }
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

    public static function unsupportedNotice(): void
    {
        $version = Environment::wooCommerceVersion();
        $message = $version === null
            ? __('Business Watchdog requires WooCommerce to be installed and active.', 'business-watchdog')
            : sprintf(__('Business Watchdog requires WooCommerce %1$s or newer (found %2$s).', 'business-watchdog'), BW_MIN_WC, $version);

        echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
    }
}
