<?php

namespace BusinessWatchdog\WooCommerce\Admin;

use BusinessWatchdog\WooCommerce\Connection\Connection;
use BusinessWatchdog\WooCommerce\Connection\PairingClient;
use BusinessWatchdog\WooCommerce\Jobs\HeartbeatJob;
use BusinessWatchdog\WooCommerce\Rest\RestController;
use BusinessWatchdog\WooCommerce\Storage\State;

final class SettingsPage
{
    public const SLUG = 'business-watchdog';

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_post_bw_pair', [self::class, 'handlePair']);
        add_action('admin_post_bw_disconnect', [self::class, 'handleDisconnect']);
    }

    public static function menu(): void
    {
        add_submenu_page('woocommerce', 'Business Watchdog', 'Business Watchdog', 'manage_woocommerce', self::SLUG, [self::class, 'render']);
    }

    public static function handlePair(): void
    {
        self::guard('bw_pair');

        $endpoint = isset($_POST['bw_endpoint']) ? esc_url_raw(wp_unslash((string) $_POST['bw_endpoint'])) : '';
        $code = isset($_POST['bw_pairing_code']) ? sanitize_text_field(wp_unslash((string) $_POST['bw_pairing_code'])) : '';
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

    public static function render(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            return;
        }

        $diagnostics = RestController::diagnosticsPayload();
        $connection = $diagnostics['connection'];
        $notice = isset($_GET['bw_notice']) ? sanitize_key((string) $_GET['bw_notice']) : '';

        echo '<div class="wrap"><h1>Business Watchdog</h1>';

        if ($notice !== '') {
            echo '<div class="notice notice-info"><p>' . esc_html($notice) . '</p></div>';
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
