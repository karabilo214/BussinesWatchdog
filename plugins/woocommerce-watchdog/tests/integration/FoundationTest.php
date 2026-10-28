<?php

use BusinessWatchdog\WooCommerce\Compat\Environment;
use BusinessWatchdog\WooCommerce\Compat\SchedulerFactory;
use BusinessWatchdog\WooCommerce\Jobs\HeartbeatJob;
use BusinessWatchdog\WooCommerce\Security\SecretBox;
use BusinessWatchdog\WooCommerce\Capture\PaymentMode;
use BusinessWatchdog\WooCommerce\Storage\LegacyNames;
use BusinessWatchdog\WooCommerce\Storage\Schema;
use BusinessWatchdog\WooCommerce\Synthetic\SyntheticOrders;
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

final class BusinessWatchdogRedirected extends RuntimeException
{
}

function bw_capture_redirect(callable $callback): string
{
    $capture = static function ($location) {
        throw new BusinessWatchdogRedirected((string) $location);
    };
    add_filter('wp_redirect', $capture, 1);

    try {
        $callback();
    } catch (BusinessWatchdogRedirected $redirect) {
        return $redirect->getMessage();
    } finally {
        remove_filter('wp_redirect', $capture, 1);
    }

    throw new RuntimeException('no redirect');
}

function bw_without_connection(callable $callback): void
{
    $connection = State::get(\BusinessWatchdog\WooCommerce\Connection\Connection::STATE_KEY);
    $admin = get_user_by('login', 'admin');
    wp_set_current_user($admin ? $admin->ID : 1);
    \BusinessWatchdog\WooCommerce\Connection\Connection::forget();

    try {
        $callback();
    } finally {
        State::set(\BusinessWatchdog\WooCommerce\Connection\Connection::STATE_KEY, $connection);
        \BusinessWatchdog\WooCommerce\Capture\OrderCapture::resetCapturingCache();
        delete_user_meta(get_current_user_id(), \BusinessWatchdog\WooCommerce\Admin\SettingsPage::CARD_HIDDEN_META);
        delete_user_meta(get_current_user_id(), \BusinessWatchdog\WooCommerce\Admin\SettingsPage::CONNECT_META);
        $_GET = [];
        $_POST = [];
        $_REQUEST = [];
    }
}

bw_test('an unconnected store shows the connect card with buttons until it is connected or hidden', function () {
    bw_without_connection(function () {
        $links = \BusinessWatchdog\WooCommerce\Admin\SettingsPage::actionLinks(['deactivate' => '<a>Deactivate</a>']);
        bw_assert(count($links) === 3 && strpos(implode('', $links), 'https://business-watchdog.com/go/wordpress-plugin?placement=plugins_list&#038;lang=en') !== false, 'signup link missing: ' . implode(' ', $links));
        bw_assert(strpos(implode('', $links), 'Connect') !== false && isset($links['deactivate']), 'connect link or WordPress links missing');

        ob_start();
        \BusinessWatchdog\WooCommerce\Admin\SettingsPage::render();
        $html = (string) ob_get_clean();
        bw_assert(strpos($html, 'data-business-watchdog-connect') !== false && strpos($html, 'value="plugin_page"') !== false, 'plugin page has no connect card');
        bw_assert(strpos($html, 'value="https://business-watchdog.com"') !== false && strpos($html, 'id="business-watchdog-code"') !== false, 'code form missing');

        set_current_screen('plugins');
        ob_start();
        \BusinessWatchdog\WooCommerce\Admin\SettingsPage::connectNotice();
        $card = (string) ob_get_clean();
        foreach (['Connect Business Watchdog', 'name="start" value="login"', 'name="start" value="signup"', 'value="plugins_card"', 'I have a connection code', 'business_watchdog_hide_card', 'name="_wpnonce"'] as $needle) {
            bw_assert(strpos($card, $needle) !== false, 'card misses ' . $needle);
        }

        update_user_meta(get_current_user_id(), \BusinessWatchdog\WooCommerce\Admin\SettingsPage::CARD_HIDDEN_META, '1');
        ob_start();
        \BusinessWatchdog\WooCommerce\Admin\SettingsPage::connectNotice();
        bw_assert((string) ob_get_clean() === '', 'hidden card still shown');
        ob_start();
        \BusinessWatchdog\WooCommerce\Admin\SettingsPage::render();
        bw_assert(strpos((string) ob_get_clean(), 'data-business-watchdog-connect') !== false, 'plugin page must keep the card');
    });

    $links = \BusinessWatchdog\WooCommerce\Admin\SettingsPage::actionLinks([]);
    bw_assert(count($links) === 1 && strpos($links[0], 'Settings') !== false, 'only settings once connected');
    set_current_screen('plugins');
    ob_start();
    \BusinessWatchdog\WooCommerce\Admin\SettingsPage::connectNotice();
    bw_assert((string) ob_get_clean() === '', 'no card once connected');
});

bw_test('one-click connect sends the site, its return page and a challenge, and pairs on return with the verifier', function () {
    bw_without_connection(function () {
        $_POST = $_REQUEST = ['action' => 'business_watchdog_connect', 'start' => 'signup', 'placement' => 'plugins_card', '_wpnonce' => wp_create_nonce('business_watchdog_connect')];
        $location = bw_capture_redirect([\BusinessWatchdog\WooCommerce\Admin\SettingsPage::class, 'handleConnect']);
        bw_assert(strpos($location, 'https://business-watchdog.com/connect/woocommerce?') === 0, 'wrong start: ' . $location);
        parse_str((string) wp_parse_url($location, PHP_URL_QUERY), $query);
        bw_assert($query['site'] === untrailingslashit(home_url()) && $query['return'] === admin_url('admin-post.php?action=business_watchdog_connect_return'), 'site or return wrong');
        bw_assert($query['start'] === 'signup' && $query['placement'] === 'plugins_card' && $query['lang'] === 'en', 'start, placement or language wrong');
        bw_assert(preg_match('/^[0-9a-f]{64}$/', $query['state']) === 1 && preg_match('/^[A-Za-z0-9_-]{43}$/', $query['challenge']) === 1, 'state or challenge malformed');

        $stored = get_user_meta(get_current_user_id(), \BusinessWatchdog\WooCommerce\Admin\SettingsPage::CONNECT_META, true);
        bw_assert(is_array($stored) && $stored['state_hash'] === hash('sha256', $query['state']) && strpos(wp_json_encode($stored), $query['state']) === false, 'state stored in clear');
        $verifier = \BusinessWatchdog\WooCommerce\Security\SecretBox::open($stored['verifier']);
        bw_assert(rtrim(strtr(base64_encode(hash('sha256', (string) $verifier, true)), '+/', '-_'), '=') === $query['challenge'], 'challenge does not match the verifier');

        $_GET = ['action' => 'business_watchdog_connect_return', 'state' => str_repeat('0', 64), 'code' => 'bwpc_wrong'];
        $location = bw_capture_redirect([\BusinessWatchdog\WooCommerce\Admin\SettingsPage::class, 'handleConnectReturn']);
        bw_assert(strpos($location, 'business_watchdog_notice=connect_expired') !== false, 'foreign state accepted');
        bw_assert(get_user_meta(get_current_user_id(), \BusinessWatchdog\WooCommerce\Admin\SettingsPage::CONNECT_META, true) === '', 'request not single use');

        update_user_meta(get_current_user_id(), \BusinessWatchdog\WooCommerce\Admin\SettingsPage::CONNECT_META, $stored);
        $sent = null;
        $fake = static function ($preempt, $args, $url) use (&$sent) {
            if (strpos((string) $url, '/api/v1/pairing/exchange') === false) {
                return ['headers' => [], 'body' => '{}', 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
            }
            $sent = json_decode((string) $args['body'], true);

            return ['headers' => [], 'body' => (string) wp_json_encode(['integration_id' => wp_generate_uuid4(), 'key_id' => 'bwk_one_click', 'secret' => base64_encode(random_bytes(32)), 'secret_encoding' => 'base64', 'signature_version' => 1]), 'response' => ['code' => 201, 'message' => 'Created'], 'cookies' => [], 'filename' => null];
        };
        add_filter('pre_http_request', $fake, 10, 3);

        try {
            $_GET = ['action' => 'business_watchdog_connect_return', 'state' => $query['state'], 'code' => 'bwpc_from_the_service_1234567890'];
            $location = bw_capture_redirect([\BusinessWatchdog\WooCommerce\Admin\SettingsPage::class, 'handleConnectReturn']);
        } finally {
            remove_filter('pre_http_request', $fake, 10);
        }

        bw_assert(strpos($location, 'business_watchdog_notice=paired') !== false && strpos($location, 'bwpc_') === false, 'not paired or code left in the address: ' . $location);
        bw_assert(is_array($sent) && $sent['pairing_code'] === 'bwpc_from_the_service_1234567890' && $sent['code_verifier'] === $verifier && $sent['base_url'] === untrailingslashit(home_url()), 'exchange body wrong');
        bw_assert(\BusinessWatchdog\WooCommerce\Connection\Connection::current() !== null, 'connection not stored');
        bw_assert(get_user_meta(get_current_user_id(), \BusinessWatchdog\WooCommerce\Admin\SettingsPage::CONNECT_META, true) === '', 'request kept after use');

        wp_set_current_user(0);
        $_GET = ['action' => 'business_watchdog_connect_return', 'state' => 'abc', 'code' => 'bwpc_x'];
        $location = bw_capture_redirect([\BusinessWatchdog\WooCommerce\Admin\SettingsPage::class, 'handleConnectReturnSignedOut']);
        bw_assert(strpos($location, 'wp-login.php') !== false && strpos(rawurldecode($location), 'action=business_watchdog_connect_return&state=abc&code=bwpc_x') !== false, 'signed-out return does not go through the login: ' . $location);
    });
});

bw_test('names from before 0.4.0 are moved with their data', function () {
    global $wpdb;

    $integrationId = \BusinessWatchdog\WooCommerce\Connection\Connection::current()->integrationId();
    $tables = ['outbox', 'revisions', 'state', 'payment_attempts', 'checkout_observation'];

    foreach ($tables as $name) {
        $wpdb->query("RENAME TABLE `{$wpdb->prefix}business_watchdog_{$name}` TO `{$wpdb->prefix}bw_{$name}`");
    }

    update_option('bw_schema_version', get_option(Schema::OPTION), false);
    delete_option(Schema::OPTION);
    update_option('bw_purge_history_on_uninstall', 'no', false);
    wp_schedule_single_event(time() + 3600, 'bw_heartbeat');

    bw_assert(LegacyNames::pending(), 'Legacy names not detected');
    LegacyNames::migrate();

    foreach ($tables as $name) {
        $current = $wpdb->prefix . 'business_watchdog_' . $name;
        bw_assert($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $current)) === $current, 'Table not moved: ' . $name);
        bw_assert($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->prefix . 'bw_' . $name)) === null, 'Legacy table left: ' . $name);
    }

    bw_assert(Schema::isCurrent(), 'Schema version not moved');
    bw_assert(get_option('business_watchdog_purge_history_on_uninstall') === 'no', 'Option not moved');
    bw_assert(get_option('bw_schema_version', null) === null && get_option('bw_purge_history_on_uninstall', null) === null, 'Legacy options left');
    bw_assert(wp_next_scheduled('bw_heartbeat') === false, 'Legacy schedule left');
    bw_assert(\BusinessWatchdog\WooCommerce\Connection\Connection::current()->integrationId() === $integrationId, 'Connection lost');
    bw_assert(! LegacyNames::pending(), 'Migration not finished');
    delete_option('business_watchdog_purge_history_on_uninstall');

    $order = wc_create_order();
    $order->set_payment_method('stripe');
    $order->update_meta_data(LegacyNames::PAYMENT_MODE_META, 'test');
    $order->update_meta_data(LegacyNames::SYNTHETIC_META, wp_generate_uuid4());
    $order->save();
    bw_assert(PaymentMode::forOrder($order) === 'test', 'Legacy payment mode not read');
    bw_assert(SyntheticOrders::isSynthetic($order), 'Legacy synthetic marker not read');
    $order->delete(true);
});
