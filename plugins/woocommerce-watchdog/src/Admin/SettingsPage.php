<?php

namespace BusinessWatchdog\WooCommerce\Admin;

use BusinessWatchdog\WooCommerce\Connection\Connection;
use BusinessWatchdog\WooCommerce\Connection\PairingClient;
use BusinessWatchdog\WooCommerce\Jobs\HeartbeatJob;
use BusinessWatchdog\WooCommerce\Rest\RestController;
use BusinessWatchdog\WooCommerce\Safety\MonitoringSwitch;
use BusinessWatchdog\WooCommerce\Safety\Guard;
use BusinessWatchdog\WooCommerce\Storage\State;

final class SettingsPage
{
    public const SLUG = 'business-watchdog';

    public const SERVICE_URL = 'https://business-watchdog.com';

    public static function register(): void
    {
        add_action('admin_menu', Guard::action([self::class, 'menu'], 'admin_menu'));
        add_action('admin_post_business_watchdog_pair', Guard::action([self::class, 'handlePair'], 'admin_pair'));
        add_action('admin_post_business_watchdog_disconnect', Guard::action([self::class, 'handleDisconnect'], 'admin_disconnect'));
        add_action('admin_post_business_watchdog_stop', Guard::action([self::class, 'handleStop'], 'admin_stop'));
        add_action('admin_post_business_watchdog_resume', Guard::action([self::class, 'handleResume'], 'admin_resume'));
        add_filter('plugin_action_links_' . plugin_basename(BUSINESS_WATCHDOG_FILE), Guard::filter([self::class, 'actionLinks'], 'action_links'));

        if (! MonitoringSwitch::stopped()) {
            add_action('admin_notices', Guard::action([self::class, 'connectNotice'], 'connect_notice'));
        }
    }

    public static function signupUrl(): string
    {
        $locale = strtolower(substr((string) get_user_locale(), 0, 2));

        return self::SERVICE_URL . '/' . (in_array($locale, ['de', 'ru'], true) ? $locale : 'en') . '/signup';
    }

    /**
     * @param array<int|string, string> $links
     * @return array<int|string, string>
     */
    public static function actionLinks($links): array
    {
        $links = is_array($links) ? $links : [];
        $own = ['<a href="' . esc_url(admin_url('admin.php?page=' . self::SLUG)) . '">' . esc_html__('Settings', 'business-watchdog') . '</a>'];

        if (Connection::current() === null) {
            $own[] = '<a href="' . esc_url(self::signupUrl()) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Create a free account', 'business-watchdog') . '</a>';
        }

        return array_merge($own, $links);
    }

    public static function connectNotice(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        if ($screen === null || $screen->id !== 'plugins' || ! current_user_can('manage_woocommerce') || Connection::current() !== null) {
            return;
        }

        echo '<div class="notice notice-info"><p><strong>' . esc_html__('Business Watchdog is not connected yet.', 'business-watchdog') . '</strong> ';
        echo esc_html__('The plugin works together with a Business Watchdog account:', 'business-watchdog') . '</p><ol>';
        echo '<li><a href="' . esc_url(self::signupUrl()) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Create a free account', 'business-watchdog') . '</a> ' . esc_html__('(14 days free, no card).', 'business-watchdog') . '</li>';
        echo '<li>' . esc_html__('In your account add your store, choose «WooCommerce plugin» and create a connection code.', 'business-watchdog') . '</li>';
        echo '<li><a href="' . esc_url(admin_url('admin.php?page=' . self::SLUG)) . '">' . esc_html__('Paste the code on the plugin page', 'business-watchdog') . '</a> ' . esc_html__('and click Connect.', 'business-watchdog') . '</li>';
        echo '</ol></div>';
    }

    public static function menu(): void
    {
        add_submenu_page('woocommerce', 'Business Watchdog', 'Business Watchdog', 'manage_woocommerce', self::SLUG, Guard::action([self::class, 'render'], 'admin_render'));
    }

    public static function handlePair(): void
    {
        self::guard('business_watchdog_pair');

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- self::guard() ran check_admin_referer('business_watchdog_pair') above.
        $endpoint = isset($_POST['business_watchdog_endpoint']) ? esc_url_raw(wp_unslash((string) $_POST['business_watchdog_endpoint'])) : '';
        $code = isset($_POST['business_watchdog_pairing_code']) ? sanitize_text_field(wp_unslash((string) $_POST['business_watchdog_pairing_code'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        $result = PairingClient::pair($endpoint, $code);

        if ($result['ok']) {
            HeartbeatJob::run();
        }

        self::redirect($result['ok'] ? 'paired' : (string) $result['code']);
    }

    public static function handleDisconnect(): void
    {
        self::guard('business_watchdog_disconnect');
        Connection::forget();
        State::delete(HeartbeatJob::STATE_PENDING_CHALLENGE);
        self::redirect('disconnected');
    }

    public static function handleStop(): void
    {
        self::guard('business_watchdog_stop');
        MonitoringSwitch::stop('admin');
        self::redirect('stopped');
    }

    public static function handleResume(): void
    {
        self::guard('business_watchdog_resume');
        MonitoringSwitch::resume();
        self::redirect('resumed');
    }

    public static function render(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            return;
        }

        $diagnostics = RestController::diagnosticsPayload();
        $connection = $diagnostics['connection'];
        $notice = isset($_GET['business_watchdog_notice']) ? sanitize_key((string) $_GET['business_watchdog_notice']) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only selects which notice to show after a redirect.

        echo '<div class="wrap"><h1>Business Watchdog</h1>';

        if ($notice !== '') {
            echo '<div class="notice notice-info"><p>' . esc_html($notice) . '</p></div>';
        }

        if (is_array($diagnostics['outbox']['full'] ?? null)) {
            /* translators: %d: maximum number of events in the local queue. */
            echo '<div class="notice notice-error"><p>' . esc_html(sprintf(__('The local queue reached %d events. New order changes are not queued until it drains; they are recovered from the orders afterwards. Orders keep working.', 'business-watchdog'), $diagnostics['outbox']['limit'])) . '</p></div>';
        } elseif (! empty($diagnostics['outbox']['backlog_over_7_days'])) {
            echo '<div class="notice notice-warning"><p>' . esc_html__('Some events have waited for delivery for more than 7 days. Monitoring data is incomplete; check the connection to Business Watchdog.', 'business-watchdog') . '</p></div>';
        }

        $stopped = MonitoringSwitch::stopped();
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field($stopped ? 'business_watchdog_resume' : 'business_watchdog_stop');
        echo '<input type="hidden" name="action" value="' . ($stopped ? 'business_watchdog_resume' : 'business_watchdog_stop') . '" />';
        echo '<p>' . esc_html($stopped
            ? __('Monitoring is stopped. Orders and checkout are not affected.', 'business-watchdog')
            : __('Emergency stop: halts all Business Watchdog work on this site at once. Orders and checkout are not affected.', 'business-watchdog')) . '</p>';
        submit_button($stopped ? __('Resume monitoring', 'business-watchdog') : __('Stop monitoring', 'business-watchdog'), 'secondary');
        echo '</form>';

        if ($stopped) {
            echo '</div>';

            return;
        }

        if ($connection === null) {
            echo '<p>' . esc_html__('To connect, you need a Business Watchdog account and a connection code from it.', 'business-watchdog') . ' ';
            echo esc_html__('No account yet?', 'business-watchdog') . ' <a href="' . esc_url(self::signupUrl()) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Create a free account', 'business-watchdog') . '</a> ' . esc_html__('(14 days free, no card), add your store, choose «WooCommerce plugin» and create a connection code.', 'business-watchdog') . '</p>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('business_watchdog_pair');
            echo '<input type="hidden" name="action" value="business_watchdog_pair" />';
            echo '<table class="form-table"><tr><th><label for="business_watchdog_endpoint">' . esc_html__('Service URL', 'business-watchdog') . '</label></th>';
            echo '<td><input class="regular-text" type="url" id="business_watchdog_endpoint" name="business_watchdog_endpoint" value="' . esc_attr(self::SERVICE_URL) . '" required /></td></tr>';
            echo '<tr><th><label for="business_watchdog_pairing_code">' . esc_html__('Pairing code', 'business-watchdog') . '</label></th>';
            echo '<td><input class="regular-text" type="text" id="business_watchdog_pairing_code" name="business_watchdog_pairing_code" autocomplete="off" required /></td></tr></table>';
            submit_button(__('Connect', 'business-watchdog'));
            echo '</form>';
        } else {
            /* translators: %s: connection status. */
            echo '<p>' . esc_html(sprintf(__('Status: %s', 'business-watchdog'), $connection['status'])) . '</p>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('business_watchdog_disconnect');
            echo '<input type="hidden" name="action" value="business_watchdog_disconnect" />';
            submit_button(__('Disconnect', 'business-watchdog'), 'secondary');
            echo '</form>';
        }

        echo '<h2>' . esc_html__('Diagnostics', 'business-watchdog') . '</h2>';
        echo '<pre>' . esc_html((string) wp_json_encode($diagnostics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre></div>';
    }

    private static function guard(string $action): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Not allowed.', 'business-watchdog'), 403);
        }

        check_admin_referer($action);
    }

    private static function redirect(string $notice): void
    {
        wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'business_watchdog_notice' => $notice], admin_url('admin.php')));
        exit;
    }
}
