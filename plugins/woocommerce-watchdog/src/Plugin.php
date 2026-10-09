<?php

namespace BusinessWatchdog\WooCommerce;

use BusinessWatchdog\WooCommerce\Admin\SettingsPage;
use BusinessWatchdog\WooCommerce\Compat\Environment;
use BusinessWatchdog\WooCommerce\Compat\SchedulerFactory;
use BusinessWatchdog\WooCommerce\Compat\WpCronAdapter;
use BusinessWatchdog\WooCommerce\Jobs\HeartbeatJob;
use BusinessWatchdog\WooCommerce\Rest\RestController;
use BusinessWatchdog\WooCommerce\Storage\Schema;
use BusinessWatchdog\WooCommerce\Storage\State;

final class Plugin
{
    public const RECURRING_HOOKS = [HeartbeatJob::HOOK];

    public static function activate(): void
    {
        Schema::install();
        State::installId();
    }

    public static function deactivate(): void
    {
        foreach (self::RECURRING_HOOKS as $hook) {
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

        add_action(HeartbeatJob::HOOK, [HeartbeatJob::class, 'run']);
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
        }
    }

    public static function ensureSchedules(): void
    {
        SchedulerFactory::make()->ensureRecurring(HeartbeatJob::HOOK, HeartbeatJob::INTERVAL_SECONDS);
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
