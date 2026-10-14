<?php

use BusinessWatchdog\WooCommerce\Jobs\ObservationReportJob;
use BusinessWatchdog\WooCommerce\Jobs\PaymentAttemptsJob;
use BusinessWatchdog\WooCommerce\Observation\CheckoutObservation;
use BusinessWatchdog\WooCommerce\Observation\ObservationLog;
use BusinessWatchdog\WooCommerce\Observation\ObservationSettings;
use BusinessWatchdog\WooCommerce\Observation\ObservationToken;
use BusinessWatchdog\WooCommerce\Storage\Schema;

function bw_observation_reset(bool $observe = true): void
{
    global $wpdb;

    $wpdb->query('DELETE FROM ' . Schema::observationTable());
    $wpdb->query('DELETE FROM ' . Schema::outboxTable() . " WHERE aggregate_key LIKE 'checkout_observation:%'");
    delete_option(CheckoutObservation::ALTERED_OPTION);
    ObservationSettings::forget();

    if ($observe) {
        ObservationSettings::remember(['observe' => true]);
    }

    CheckoutObservation::reset();
}

function bw_observation_rows(): array
{
    global $wpdb;

    return (array) $wpdb->get_results('SELECT * FROM ' . Schema::observationTable() . ' ORDER BY window_start', ARRAY_A);
}

function bw_observation_page(?string $page): void
{
    $property = new ReflectionProperty(CheckoutObservation::class, 'page');
    $property->setAccessible(true);
    $property->setValue(null, $page);
}

function bw_observe_request(array $body, string $origin = ''): WP_REST_Response
{
    $request = new WP_REST_Request('POST', '/business-watchdog/v1/observe');
    $request->set_header('content-type', 'text/plain;charset=UTF-8');

    if ($origin !== '') {
        $request->set_header('origin', $origin);
    }

    $request->set_body((string) wp_json_encode($body));

    return rest_do_request($request);
}

bw_test('observation: the service decides through the heartbeat answer', function () {
    bw_observation_reset(false);
    bw_assert(! ObservationSettings::enabled(), 'off without an answer');
    ObservationSettings::remember(['observe' => true]);
    bw_assert(ObservationSettings::enabled(), 'on after observe=true');
    ObservationSettings::remember(null);
    bw_assert(! ObservationSettings::enabled(), 'an answer without the field switches it off');
    bw_assert(wp_load_alloptions()[ObservationSettings::OPTION] ?? null, 'kept in an autoloaded option (no query on storefront pages)');
});

bw_test('observation: tokens carry only the page and an expiry and cannot be made up', function () {
    $token = ObservationToken::issue('checkout');
    bw_assert(ObservationToken::verify($token) === 'checkout', 'valid token');
    bw_assert(ObservationToken::verify($token, time() + ObservationToken::TTL_SECONDS + 5) === null, 'expired token');
    bw_assert(ObservationToken::verify(substr($token, 0, -2) . 'aa') === null, 'tampered token');
    bw_assert(ObservationToken::verify('') === null, 'no token');
    $payload = json_decode((string) base64_decode(strtr(explode('.', $token)[0], '-_', '+/')), true);
    bw_assert(array_keys($payload) === ['p', 'e'], 'nothing but page and expiry: ' . wp_json_encode($payload));
});

bw_test('observation: reports count stages of an observed page only, never from a browser check', function () {
    bw_observation_reset();
    $token = ObservationToken::issue('checkout');
    $response = bw_observe_request(['token' => $token, 'page' => 'checkout', 'stages' => ['script_loaded', 'form_ready', 'submit_clicked', 'card_number', 'script_loaded'], 'js_errors' => 99], home_url());
    bw_assert($response->get_status() === 204, 'answers 204');
    bw_observe_request(['token' => $token, 'page' => 'finish', 'stages' => ['finish_reached']]);
    bw_observe_request(['token' => 'v1.forged.token', 'page' => 'checkout', 'stages' => ['submit_clicked']]);
    bw_observe_request(['token' => $token, 'page' => 'checkout', 'stages' => ['submit_clicked']], 'https://evil.example.invalid');
    bw_with_synthetic(bw_synthetic_token(), function () use ($token) {
        bw_observe_request(['token' => $token, 'page' => 'checkout', 'stages' => ['submit_clicked']]);
    });
    bw_observe_request(['token' => $token, 'page' => 'checkout', 'stages' => ['script_altered']]);

    $rows = bw_observation_rows();
    bw_assert(count($rows) === 1, 'one window');
    $row = $rows[0];
    bw_assert([(int) $row['script_loaded'], (int) $row['form_ready'], (int) $row['submit_clicked'], (int) $row['finish_reached'], (int) $row['js_errors']] === [1, 1, 1, 0, 20], 'counted: ' . wp_json_encode($row));
    bw_assert(get_option(CheckoutObservation::ALTERED_OPTION) !== false, 'an altered script is noted for diagnostics');

    ObservationSettings::forget();
    bw_observe_request(['token' => $token, 'page' => 'checkout', 'stages' => ['submit_clicked']]);
    bw_assert((int) bw_observation_rows()[0]['submit_clicked'] === 1, 'nothing counted once the service says no');
});

bw_test('observation: rendered pages are counted by the plugin with a noscript beacon, browser checks excluded', function () {
    bw_observation_reset();
    bw_observation_page('checkout');
    ob_start();
    CheckoutObservation::rendered();
    $html = (string) ob_get_clean();
    bw_assert(strpos($html, '<noscript><img src="') === 0 && strpos(urldecode(html_entity_decode($html)), 'business-watchdog/v1/observe/noscript') !== false, 'noscript beacon printed: ' . $html);
    bw_with_synthetic(bw_synthetic_token(), function () {
        ob_start();
        CheckoutObservation::rendered();
        ob_end_clean();
    });
    bw_observation_page('finish');
    ob_start();
    CheckoutObservation::rendered();
    bw_assert((string) ob_get_clean() === '', 'no noscript beacon on the order-received page');

    preg_match('/[?&]t=([^"&]+)/', html_entity_decode($html), $match);
    $request = new WP_REST_Request('GET', '/business-watchdog/v1/observe/noscript');
    $request->set_param('t', urldecode($match[1]));
    rest_do_request($request);

    $row = bw_observation_rows()[0];
    bw_assert([(int) $row['checkout_rendered'], (int) $row['finish_rendered'], (int) $row['checkout_rendered_noscript']] === [1, 1, 1], 'pages counted: ' . wp_json_encode($row));
    bw_observation_page(null);
});

bw_test('observation: the script tag is kept out of optimisers', function () {
    $tag = CheckoutObservation::tag('<script src="https://shop/wp-content/plugins/business-watchdog/assets/checkout-observer.js"></script>', CheckoutObservation::HANDLE);
    bw_assert(strpos($tag, 'data-no-optimize="1"') !== false && strpos($tag, 'data-cfasync="false"') !== false && strpos($tag, 'data-no-defer="1"') !== false, 'tag attributes');
    bw_assert(CheckoutObservation::tag('<script src="x"></script>', 'jquery') === '<script src="x"></script>', 'other scripts untouched');
    bw_assert(in_array('business-watchdog/assets/checkout-observer', CheckoutObservation::excludePatterns(['other']), true), 'WP Rocket / LiteSpeed patterns');
    bw_assert(in_array(CheckoutObservation::HANDLE, CheckoutObservation::excludeHandle([]), true), 'SiteGround handles');
    bw_assert(strpos(CheckoutObservation::excludeAutoptimize('a.js'), 'BW_CHECKOUT_OBSERVER') !== false, 'Autoptimize list');
    $source = (string) file_get_contents(BW_PLUGIN_DIR . '/assets/checkout-observer.js');
    foreach (['localStorage', 'sessionStorage', 'document.cookie', '.value'] as $forbidden) {
        bw_assert(strpos($source, $forbidden) === false, "the script must not use {$forbidden}");
    }
});

bw_test('observation: closed windows go to the outbox once, a late change is sent again with a higher revision', function () {
    global $wpdb;

    bw_observation_reset();
    $now = time();
    $old = PaymentAttemptsJob::windowStart($now) - 900;
    ObservationLog::increment(['checkout_rendered' => 2, 'submit_clicked' => 1], $old);
    ObservationLog::increment(['checkout_rendered' => 1], $now);

    bw_assert(ObservationReportJob::run($now) === 1, 'only the closed window is sent');
    bw_assert(ObservationReportJob::run($now) === 0, 'not sent twice');
    $events = $wpdb->get_results('SELECT payload FROM ' . Schema::outboxTable() . " WHERE aggregate_key LIKE 'checkout_observation:%' ORDER BY id", ARRAY_A);
    $event = json_decode($events[0]['payload'], true);
    bw_assert($event['type'] === 'checkout.observation' && $event['aggregate_type'] === 'checkout' && $event['aggregate_id'] === 'observation:' . gmdate('Y-m-d\TH:i:s\Z', $old) && $event['aggregate_revision'] === 1, 'envelope: ' . wp_json_encode($event));
    bw_assert($event['data']['checkout_rendered'] === 2 && $event['data']['submit_clicked'] === 1 && $event['data']['window_end'] === gmdate('Y-m-d\TH:i:s\Z', $old + 300), 'data');
    bw_assert(array_keys($event['data']) === array_merge(['window_start', 'window_end'], ObservationLog::COUNTERS), 'only counters leave the shop');

    ObservationLog::increment(['finish_reached' => 1], $old);
    bw_assert(ObservationReportJob::run($now) === 1, 'the changed window is sent again');
    $again = json_decode($wpdb->get_var('SELECT payload FROM ' . Schema::outboxTable() . " WHERE aggregate_key LIKE 'checkout_observation:%' ORDER BY id DESC LIMIT 1"), true);
    bw_assert($again['aggregate_revision'] === 2 && $again['data']['finish_reached'] === 1 && $again['data']['checkout_rendered'] === 2, 'revision 2 with all counters');
    bw_observation_reset(false);
});
