<?php

namespace BusinessWatchdog\WooCommerce\Rest;

use BusinessWatchdog\WooCommerce\Compat\Environment;
use BusinessWatchdog\WooCommerce\Connection\Connection;
use BusinessWatchdog\WooCommerce\Connection\PairingClient;
use BusinessWatchdog\WooCommerce\Jobs\HeartbeatJob;
use BusinessWatchdog\WooCommerce\Storage\OutboxStats;
use BusinessWatchdog\WooCommerce\Storage\State;

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

        add_filter('rest_pre_serve_request', [self::class, 'servePlainChallenge'], 10, 4);
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

    public static function servePlainChallenge($served, $result, $request, $server)
    {
        if ($served || ! $request instanceof \WP_REST_Request || ! $result instanceof \WP_REST_Response) {
            return $served;
        }

        if (preg_match('#^/' . preg_quote(self::NAMESPACE, '#') . '/challenge/#', $request->get_route()) !== 1 || $result->get_status() !== 200) {
            return $served;
        }

        header('Content-Type: text/plain; charset=utf-8');
        echo (string) $result->get_data();

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
            ],
            'last_delivery' => State::get(\BusinessWatchdog\WooCommerce\Jobs\DeliveryJob::STATE_LAST),
            'last_rescan' => State::get(\BusinessWatchdog\WooCommerce\Jobs\RescanJob::STATE_LAST),
            'backfill' => State::get(\BusinessWatchdog\WooCommerce\Jobs\BackfillJob::STATE),
            'last_capture_error' => State::get(\BusinessWatchdog\WooCommerce\Capture\OrderCapture::STATE_LAST_ERROR),
            'site_base_url' => PairingClient::siteBaseUrl(),
            'last_synthetic_check' => State::get(\BusinessWatchdog\WooCommerce\Synthetic\SyntheticOrders::STATE_LAST),
        ];
    }
}
