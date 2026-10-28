<?php

namespace BusinessWatchdog\WooCommerce\Admin;

use BusinessWatchdog\WooCommerce\Connection\Connection;
use BusinessWatchdog\WooCommerce\Connection\PairingClient;
use BusinessWatchdog\WooCommerce\Http\Transport;
use BusinessWatchdog\WooCommerce\Jobs\HeartbeatJob;
use BusinessWatchdog\WooCommerce\Rest\RestController;
use BusinessWatchdog\WooCommerce\Safety\MonitoringSwitch;
use BusinessWatchdog\WooCommerce\Safety\Guard;
use BusinessWatchdog\WooCommerce\Security\SecretBox;
use BusinessWatchdog\WooCommerce\Storage\State;

final class SettingsPage
{
    public const SLUG = 'business-watchdog';

    public const SERVICE_URL = 'https://business-watchdog.com';

    public const CONNECT_META = 'business_watchdog_connect';

    public const CARD_HIDDEN_META = 'business_watchdog_card_hidden';

    public const CONNECT_TTL = 1800;

    private const PLACEMENTS = ['plugins_card', 'plugin_page'];

    private const NOTICES = ['paired', 'disconnected', 'stopped', 'resumed', 'connect_expired'];

    public static function register(): void
    {
        add_action('admin_menu', Guard::action([self::class, 'menu'], 'admin_menu'));
        add_action('admin_post_business_watchdog_pair', Guard::action([self::class, 'handlePair'], 'admin_pair'));
        add_action('admin_post_business_watchdog_connect', Guard::action([self::class, 'handleConnect'], 'admin_connect'));
        add_action('admin_post_business_watchdog_connect_return', Guard::action([self::class, 'handleConnectReturn'], 'admin_connect_return'));
        add_action('admin_post_nopriv_business_watchdog_connect_return', Guard::action([self::class, 'handleConnectReturnSignedOut'], 'admin_connect_return_signed_out'));
        add_action('admin_post_business_watchdog_hide_card', Guard::action([self::class, 'handleHideCard'], 'admin_hide_card'));
        add_action('admin_post_business_watchdog_disconnect', Guard::action([self::class, 'handleDisconnect'], 'admin_disconnect'));
        add_action('admin_post_business_watchdog_stop', Guard::action([self::class, 'handleStop'], 'admin_stop'));
        add_action('admin_post_business_watchdog_resume', Guard::action([self::class, 'handleResume'], 'admin_resume'));
        add_filter('plugin_action_links_' . plugin_basename(BUSINESS_WATCHDOG_FILE), Guard::filter([self::class, 'actionLinks'], 'action_links'));

        if (! MonitoringSwitch::stopped()) {
            add_action('admin_notices', Guard::action([self::class, 'connectNotice'], 'connect_notice'));
        }
    }

    public static function serviceUrl(): string
    {
        return defined('BUSINESS_WATCHDOG_SERVICE_URL') ? untrailingslashit((string) BUSINESS_WATCHDOG_SERVICE_URL) : self::SERVICE_URL;
    }

    public static function language(): string
    {
        $locale = strtolower(substr((string) get_user_locale(), 0, 2));

        return in_array($locale, ['de', 'ru'], true) ? $locale : 'en';
    }

    public static function signupUrl(string $placement = 'plugins_list'): string
    {
        return self::serviceUrl() . '/go/wordpress-plugin?' . http_build_query(['placement' => $placement, 'lang' => self::language()]);
    }

    /**
     * @param array<int|string, string> $links
     * @return array<int|string, string>
     */
    public static function actionLinks($links): array
    {
        $links = is_array($links) ? $links : [];
        $connected = Connection::current() !== null;
        $own = ['<a href="' . esc_url(admin_url('admin.php?page=' . self::SLUG)) . '">' . ($connected ? esc_html__('Settings', 'business-watchdog') : '<strong>' . esc_html__('Connect', 'business-watchdog') . '</strong>') . '</a>'];

        if (! $connected) {
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

        if (get_user_meta(get_current_user_id(), self::CARD_HIDDEN_META, true) === '1') {
            return;
        }

        $hide = wp_nonce_url(admin_url('admin-post.php?action=business_watchdog_hide_card'), 'business_watchdog_hide_card');
        echo '<div class="notice notice-info" data-business-watchdog-card style="padding:16px 20px;border-left-width:4px">';
        self::connectCard('plugins_card');
        echo '<p style="margin:12px 0 0"><a href="' . esc_url($hide) . '">' . esc_html__('Hide this card', 'business-watchdog') . '</a> <span class="description">' . esc_html__('(it stays on the plugin page)', 'business-watchdog') . '</span></p>';
        echo '</div>';
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
        self::finishPairing(PairingClient::pair($endpoint, $code));
    }

    public static function handleConnect(): void
    {
        self::guard('business_watchdog_connect');

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- self::guard() ran check_admin_referer('business_watchdog_connect') above.
        $start = isset($_POST['start']) && sanitize_key(wp_unslash((string) $_POST['start'])) === 'signup' ? 'signup' : 'login';
        $placement = isset($_POST['placement']) ? sanitize_key(wp_unslash((string) $_POST['placement'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        $endpoint = self::serviceUrl();

        if (! Transport::endpointAllowed($endpoint)) {
            self::redirect('endpoint_not_https');
        }

        $state = bin2hex(random_bytes(32));
        $verifier = self::base64url(random_bytes(32));
        update_user_meta(get_current_user_id(), self::CONNECT_META, [
            'state_hash' => hash('sha256', $state),
            'verifier' => SecretBox::seal($verifier),
            'endpoint' => $endpoint,
            'expires' => time() + self::CONNECT_TTL,
        ]);

        $url = $endpoint . '/connect/woocommerce?' . http_build_query([
            'site' => PairingClient::siteBaseUrl(),
            'return' => admin_url('admin-post.php?action=business_watchdog_connect_return'),
            'state' => $state,
            'challenge' => self::base64url(hash('sha256', $verifier, true)),
            'lang' => self::language(),
            'placement' => in_array($placement, self::PLACEMENTS, true) ? $placement : 'plugin_page',
            'start' => $start,
        ]);

        $host = (string) wp_parse_url($endpoint, PHP_URL_HOST);
        add_filter('allowed_redirect_hosts', static function ($hosts) use ($host) {
            return array_merge((array) $hosts, [$host]);
        });
        wp_safe_redirect($url);
        exit;
    }

    public static function handleConnectReturn(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Not allowed.', 'business-watchdog'), 403);
        }

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- the one-time state below is the request's CSRF check: it was created for this user and is compared with its stored hash.
        $state = isset($_GET['state']) ? sanitize_text_field(wp_unslash((string) $_GET['state'])) : '';
        $code = isset($_GET['code']) ? sanitize_text_field(wp_unslash((string) $_GET['code'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        $userId = get_current_user_id();
        $request = get_user_meta($userId, self::CONNECT_META, true);
        delete_user_meta($userId, self::CONNECT_META);

        if (! is_array($request) || (int) ($request['expires'] ?? 0) < time() || ! is_string($request['state_hash'] ?? null)
            || $state === '' || $code === '' || ! hash_equals($request['state_hash'], hash('sha256', $state))) {
            self::redirect('connect_expired');
        }

        $verifier = SecretBox::open((array) ($request['verifier'] ?? []));

        if ($verifier === null) {
            self::redirect('connect_expired');
        }

        self::finishPairing(PairingClient::pair((string) $request['endpoint'], $code, $verifier));
    }

    public static function handleConnectReturnSignedOut(): void
    {
        $query = [];

        foreach (['state', 'code'] as $name) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only carried through the login page back to the signed-in handler, which checks the state.
            $query[$name] = isset($_GET[$name]) ? sanitize_text_field(wp_unslash((string) $_GET[$name])) : '';
        }

        wp_safe_redirect(wp_login_url(add_query_arg($query, admin_url('admin-post.php?action=business_watchdog_connect_return'))));
        exit;
    }

    public static function handleHideCard(): void
    {
        self::guard('business_watchdog_hide_card');
        update_user_meta(get_current_user_id(), self::CARD_HIDDEN_META, '1');
        wp_safe_redirect(admin_url('plugins.php'));
        exit;
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
            echo '<div class="notice ' . ($notice === 'paired' ? 'notice-success' : 'notice-info') . '"><p>' . esc_html(self::noticeText($notice)) . '</p></div>';
        }

        if (is_array($diagnostics['outbox']['full'] ?? null)) {
            /* translators: %d: maximum number of events in the local queue. */
            echo '<div class="notice notice-error"><p>' . esc_html(sprintf(__('The local queue reached %d events. New order changes are not queued until it drains; they are recovered from the orders afterwards. Orders keep working.', 'business-watchdog'), $diagnostics['outbox']['limit'])) . '</p></div>';
        } elseif (! empty($diagnostics['outbox']['backlog_over_7_days'])) {
            echo '<div class="notice notice-warning"><p>' . esc_html__('Some events have waited for delivery for more than 7 days. Monitoring data is incomplete; check the connection to Business Watchdog.', 'business-watchdog') . '</p></div>';
        }

        $stopped = MonitoringSwitch::stopped();

        if ($connection === null && ! $stopped) {
            echo '<div class="card" data-business-watchdog-connect style="max-width:720px;padding:16px 20px">';
            self::connectCard('plugin_page');
            echo '</div>';
        }

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
            echo '<h2 id="business-watchdog-code">' . esc_html__('Connect with a code', 'business-watchdog') . '</h2>';
            echo '<p>' . esc_html__('For example from another browser: in your account open the store, choose «WooCommerce plugin», create a connection code and paste it here.', 'business-watchdog') . '</p>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('business_watchdog_pair');
            echo '<input type="hidden" name="action" value="business_watchdog_pair" />';
            echo '<table class="form-table"><tr><th><label for="business_watchdog_endpoint">' . esc_html__('Service URL', 'business-watchdog') . '</label></th>';
            echo '<td><input class="regular-text" type="url" id="business_watchdog_endpoint" name="business_watchdog_endpoint" value="' . esc_attr(self::serviceUrl()) . '" required /></td></tr>';
            echo '<tr><th><label for="business_watchdog_pairing_code">' . esc_html__('Pairing code', 'business-watchdog') . '</label></th>';
            echo '<td><input class="regular-text" type="text" id="business_watchdog_pairing_code" name="business_watchdog_pairing_code" autocomplete="off" required /></td></tr></table>';
            submit_button(__('Connect', 'business-watchdog'), 'secondary');
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

    private static function connectCard(string $placement): void
    {
        echo '<h2 style="margin:0 0 8px">' . esc_html__('Connect Business Watchdog', 'business-watchdog') . '</h2>';
        echo '<p style="margin:0 0 12px;max-width:640px">' . esc_html__('The plugin works with a Business Watchdog account: there it compares your orders with Stripe and PayPal and alerts you when a paid order stays unpaid. Sign in or create a free account (14 days free, no card) — you come back here connected, without copying a code.', 'business-watchdog') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">';
        wp_nonce_field('business_watchdog_connect');
        echo '<input type="hidden" name="action" value="business_watchdog_connect" />';
        echo '<input type="hidden" name="placement" value="' . esc_attr($placement) . '" />';
        echo '<button type="submit" name="start" value="login" class="button button-primary">' . esc_html__('Connect to Business Watchdog', 'business-watchdog') . '</button>';
        echo '<button type="submit" name="start" value="signup" class="button">' . esc_html__('Create a free account', 'business-watchdog') . '</button>';
        echo '<a href="' . esc_url(admin_url('admin.php?page=' . self::SLUG . '#business-watchdog-code')) . '">' . esc_html__('I have a connection code', 'business-watchdog') . '</a>';
        echo '</form>';
    }

    private static function noticeText(string $notice): string
    {
        switch (in_array($notice, self::NOTICES, true) ? $notice : 'other') {
            case 'paired':
                return __('Connected to Business Watchdog. The first data appears in your account within a few minutes.', 'business-watchdog');
            case 'disconnected':
                return __('Disconnected from Business Watchdog.', 'business-watchdog');
            case 'stopped':
                return __('Monitoring is stopped.', 'business-watchdog');
            case 'resumed':
                return __('Monitoring is resumed.', 'business-watchdog');
            case 'connect_expired':
                return __('The connection request expired or does not belong to you. Click «Connect to Business Watchdog» again.', 'business-watchdog');
            default:
                /* translators: %s: error code from the service. */
                return sprintf(__('Connecting failed: %s. Try again or connect with a code from your account.', 'business-watchdog'), $notice);
        }
    }

    private static function finishPairing(array $result): void
    {
        if ($result['ok']) {
            HeartbeatJob::run();
        }

        self::redirect($result['ok'] ? 'paired' : (string) $result['code']);
    }

    private static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
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
