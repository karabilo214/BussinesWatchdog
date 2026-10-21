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

    public static function register(): void
    {
        add_action('admin_menu', Guard::action([self::class, 'menu'], 'admin_menu'));
        add_action('admin_post_bw_pair', Guard::action([self::class, 'handlePair'], 'admin_pair'));
        add_action('admin_post_bw_disconnect', Guard::action([self::class, 'handleDisconnect'], 'admin_disconnect'));
        add_action('admin_post_bw_stop', Guard::action([self::class, 'handleStop'], 'admin_stop'));
        add_action('admin_post_bw_resume', Guard::action([self::class, 'handleResume'], 'admin_resume'));
    }

    public static function menu(): void
    {
        add_submenu_page('woocommerce', 'Business Watchdog', 'Business Watchdog', 'manage_woocommerce', self::SLUG, Guard::action([self::class, 'render'], 'admin_render'));
    }

    public static function handlePair(): void
    {
        self::guard('bw_pair');

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- self::guard() ran check_admin_referer('bw_pair') above.
        $endpoint = isset($_POST['bw_endpoint']) ? esc_url_raw(wp_unslash((string) $_POST['bw_endpoint'])) : '';
        $code = isset($_POST['bw_pairing_code']) ? sanitize_text_field(wp_unslash((string) $_POST['bw_pairing_code'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        $result = PairingClient::pair($endpoint, $code);

        if ($result['ok']) {
            HeartbeatJob::run();
        }

        self::redirect($result['ok'] ? 'paired' : (string) $result['code']);
    }

    public static function handleDisconnect(): void
    {
        self::guard('bw_disconnect');
        Connection::forget();
        State::delete(HeartbeatJob::STATE_PENDING_CHALLENGE);
        self::redirect('disconnected');
    }

    public static function handleStop(): void
    {
        self::guard('bw_stop');
        MonitoringSwitch::stop('admin');
        self::redirect('stopped');
    }

    public static function handleResume(): void
    {
        self::guard('bw_resume');
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
        $notice = isset($_GET['bw_notice']) ? sanitize_key((string) $_GET['bw_notice']) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only selects which notice to show after a redirect.

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
        wp_nonce_field($stopped ? 'bw_resume' : 'bw_stop');
        echo '<input type="hidden" name="action" value="' . ($stopped ? 'bw_resume' : 'bw_stop') . '" />';
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
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('bw_pair');
            echo '<input type="hidden" name="action" value="bw_pair" />';
            echo '<table class="form-table"><tr><th><label for="bw_endpoint">' . esc_html__('Service URL', 'business-watchdog') . '</label></th>';
            echo '<td><input class="regular-text" type="url" id="bw_endpoint" name="bw_endpoint" required /></td></tr>';
            echo '<tr><th><label for="bw_pairing_code">' . esc_html__('Pairing code', 'business-watchdog') . '</label></th>';
            echo '<td><input class="regular-text" type="text" id="bw_pairing_code" name="bw_pairing_code" autocomplete="off" required /></td></tr></table>';
            submit_button(__('Connect', 'business-watchdog'));
            echo '</form>';
        } else {
            /* translators: %s: connection status. */
            echo '<p>' . esc_html(sprintf(__('Status: %s', 'business-watchdog'), $connection['status'])) . '</p>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('bw_disconnect');
            echo '<input type="hidden" name="action" value="bw_disconnect" />';
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
        wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'bw_notice' => $notice], admin_url('admin.php')));
        exit;
    }
}
