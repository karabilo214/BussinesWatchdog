<?php

namespace BusinessWatchdog\WooCommerce\Rest;

use BusinessWatchdog\WooCommerce\Compat\Environment;
use BusinessWatchdog\WooCommerce\Connection\Connection;
use BusinessWatchdog\WooCommerce\Connection\PairingClient;
use BusinessWatchdog\WooCommerce\Jobs\HeartbeatJob;
use BusinessWatchdog\WooCommerce\Observation\CheckoutObservation;
use BusinessWatchdog\WooCommerce\Observation\ObservationLog;
use BusinessWatchdog\WooCommerce\Observation\ObservationSettings;
use BusinessWatchdog\WooCommerce\Observation\ObservationToken;
use BusinessWatchdog\WooCommerce\Synthetic\SyntheticRequest;
use BusinessWatchdog\WooCommerce\Storage\OutboxStats;
use BusinessWatchdog\WooCommerce\Storage\State;
use BusinessWatchdog\WooCommerce\Safety\Guard;

final class RestController
{
    public const NAMESPACE = 'business-watchdog/v1';

    private const CHALLENGE_ROUTE = '/challenge/(?P<id>[0-9a-fA-F-]{36})';

    public static function register(): void
    {
        register_rest_route(self::NAMESPACE, '/pair', [
            'methods' => 'POST',
            'callback' => [self::class, 'pair'],
            'permission_callback' => [self::class, 'canManage'],
            'args' => [
                'endpoint' => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'esc_url_raw'],
                'pairing_code' => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/disconnect', [
            'methods' => 'POST',
            'callback' => [self::class, 'disconnect'],
            'permission_callback' => [self::class, 'canManage'],
        ]);

        register_rest_route(self::NAMESPACE, '/diagnostics', [
            'methods' => 'GET',
            'callback' => [self::class, 'diagnostics'],
            'permission_callback' => [self::class, 'canManage'],
        ]);

        register_rest_route(self::NAMESPACE, self::CHALLENGE_ROUTE, [
            'methods' => 'GET',
            'callback' => [self::class, 'challenge'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(self::NAMESPACE, '/observe', [
            'methods' => 'POST',
            'callback' => [self::class, 'observe'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(self::NAMESPACE, '/observe/noscript', [
            'methods' => 'GET',
            'callback' => [self::class, 'observeNoscript'],
            'permission_callback' => '__return_true',
        ]);

        add_filter('rest_pre_serve_request', Guard::filter([self::class, 'servePlainChallenge'], 'rest_plain_challenge'), 10, 4);
        add_filter('rest_pre_serve_request', Guard::filter([self::class, 'serveBeaconImage'], 'rest_beacon_image'), 10, 4);
    }

    public static function canManage(): bool
    {
        return current_user_can('manage_woocommerce');
    }

    public static function pair(\WP_REST_Request $request)
    {
        $result = PairingClient::pair((string) $request->get_param('endpoint'), (string) $request->get_param('pairing_code'));

        if (! $result['ok']) {
            return new \WP_Error((string) $result['code'], __('Pairing failed.', 'business-watchdog'), ['status' => 422]);
        }

        HeartbeatJob::run();

        return rest_ensure_response(self::diagnosticsPayload());
    }

    public static function disconnect()
    {
        Connection::forget();
        State::delete(HeartbeatJob::STATE_PENDING_CHALLENGE);

        return rest_ensure_response(['connected' => false]);
    }

    public static function diagnostics()
    {
        return rest_ensure_response(self::diagnosticsPayload());
    }

    public static function challenge(\WP_REST_Request $request)
    {
        $pending = State::get(HeartbeatJob::STATE_PENDING_CHALLENGE);

        if (! is_array($pending) || ! isset($pending['id'], $pending['challenge'])
            || ! hash_equals(strtolower((string) $pending['id']), strtolower((string) $request->get_param('id')))) {
            return new \WP_Error('not_found', 'Not found.', ['status' => 404]);
        }

        $response = new \WP_REST_Response((string) $pending['challenge'], 200);
        $response->header('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * A report of the checkout observation script (ADR 0059): sent with sendBeacon as plain text, accepted only with a
     * token of a rendered page and only from the shop's own pages. Always 204.
     */
    public static function observe(\WP_REST_Request $request)
    {
        try {
            $body = json_decode(substr((string) $request->get_body(), 0, 2000), true);
            $origin = (string) $request->get_header('origin');

            if (is_array($body) && ($origin === '' || self::sameSite($origin))) {
                CheckoutObservation::report(
                    ObservationToken::verify(is_string($body['token'] ?? null) ? $body['token'] : ''),
                    is_string($body['page'] ?? null) ? $body['page'] : '',
                    is_array($body['stages'] ?? null) ? array_values($body['stages']) : [],
                    is_int($body['js_errors'] ?? null) ? max(0, $body['js_errors']) : 0
                );
            }
        } catch (\Throwable $exception) {
            // This optional beacon must never affect the shop's Store API or checkout response.
        }

        return new \WP_REST_Response(null, 204);
    }

    public static function observeNoscript(\WP_REST_Request $request)
    {
        try {
            $page = ObservationToken::verify((string) $request->get_param('t'));
            $stage = (string) $request->get_param('s');

            if ($page !== null && ObservationSettings::enabled() && SyntheticRequest::current() === null) {
                if ($stage === 'rendered') {
                    ObservationLog::increment([$page === 'finish' ? 'finish_rendered' : 'checkout_rendered' => 1]);
                } elseif ($page === 'checkout' && $stage === 'rendered_noscript') {
                    ObservationLog::increment(['checkout_rendered_noscript' => 1]);
                }
            }
        } catch (\Throwable $exception) {
            // The request is an optional one-pixel resource, never a purchase prerequisite.
        }

        return new \WP_REST_Response('beacon', 200);
    }

    public static function serveBeaconImage($served, $result, $request, $server)
    {
        if ($served || ! $request instanceof \WP_REST_Request || $request->get_route() !== '/' . self::NAMESPACE . '/observe/noscript') {
            return $served;
        }

        header('Content-Type: image/gif');
        header('Cache-Control: no-store');
        echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- a fixed 1x1 GIF image, not HTML.

        return true;
    }

    private static function sameSite(string $origin): bool
    {
        $host = strtolower((string) wp_parse_url($origin, PHP_URL_HOST));

        return $host !== '' && $host === strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
    }

    public static function servePlainChallenge($served, $result, $request, $server)
    {
        if ($served || ! $request instanceof \WP_REST_Request || ! $result instanceof \WP_REST_Response) {
            return $served;
        }

        if (preg_match('#^/' . preg_quote(self::NAMESPACE, '#') . '/challenge/#', $request->get_route()) !== 1 || $result->get_status() !== 200) {
            return $served;
        }

        header('Content-Type: text/plain; charset=utf-8');
        echo esc_html((string) $result->get_data());

        return true;
    }

    public static function diagnosticsPayload(): array
    {
        $connection = Connection::current();

        return [
            'environment' => Environment::describe(),
            'scheduler' => \BusinessWatchdog\WooCommerce\Compat\SchedulerFactory::make()->name(),
            'connection' => $connection ? $connection->publicSummary() : null,
            'last_heartbeat' => State::get(HeartbeatJob::STATE_LAST),
            'outbox' => [
                'backlog_count' => OutboxStats::backlogCount(),
                'oldest_pending_at' => OutboxStats::oldestPendingAt(),
                'dead_letter_count' => OutboxStats::deadLetterCount(),
                'total_count' => OutboxStats::totalCount(),
                'limit' => \BusinessWatchdog\WooCommerce\Storage\Outbox::LIMIT,
                'full' => \BusinessWatchdog\WooCommerce\Storage\Outbox::fullState(),
                'backlog_over_7_days' => OutboxStats::backlogOlderThanDays(7),
                'last_recovery' => State::get('outbox_last_recovery'),
            ],
            'monitoring_stopped_since' => \BusinessWatchdog\WooCommerce\Safety\MonitoringSwitch::stoppedSince(),
            'last_delivery' => State::get(\BusinessWatchdog\WooCommerce\Jobs\DeliveryJob::STATE_LAST),
            'last_rescan' => State::get(\BusinessWatchdog\WooCommerce\Jobs\RescanJob::STATE_LAST),
            'backfill' => State::get(\BusinessWatchdog\WooCommerce\Jobs\BackfillJob::STATE),
            'last_capture_error' => State::get(\BusinessWatchdog\WooCommerce\Capture\OrderCapture::STATE_LAST_ERROR),
            'site_base_url' => PairingClient::siteBaseUrl(),
            'last_synthetic_check' => State::get(\BusinessWatchdog\WooCommerce\Synthetic\SyntheticOrders::STATE_LAST),
            'checkout_observation' => ObservationSettings::describe(),
        ];
    }
}
