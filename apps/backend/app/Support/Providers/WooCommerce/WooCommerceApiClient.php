<?php

namespace App\Support\Providers\WooCommerce;

use App\Support\Network\StoreOriginFailure;
use App\Support\Network\StoreOriginHttp;
use App\Support\Network\StoreProtectionPage;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * Talks to a store's WooCommerce REST API with a Read key (ADR 0024). Only GETs on the order routes leave the
 * service; the single exception is the connect-time permission probe, a POST to a route that has no write handler,
 * which WooCommerce rejects before routing for a Read key. Requests go only to the store's own public HTTPS origin,
 * resolved once and pinned (no redirects), like the store verification fetcher.
 */
class WooCommerceApiClient
{
    public const PROBE_ROUTE = '/wc/v3/system_status';

    private const GET_ALLOWLIST = [
        '#^/wc/v3$#',
        '#^/wc/v3/orders$#',
        '#^/wc/v3/orders/[0-9]{1,20}/refunds$#',
    ];

    public function __construct(
        private readonly StoreOriginHttp $http,
    ) {}

    /**
     * @param  array<string, scalar>  $query
     * @return array{json: mixed, total: ?int, total_pages: ?int}
     */
    public function get(string $baseUrl, string $key, string $secret, string $route, array $query = []): array
    {
        if (! self::allowed('GET', $route)) {
            throw new WooCommerceApiRequestFailed('woocommerce_endpoint_not_allowed');
        }

        $response = $this->send($baseUrl, $key, $secret, fn (PendingRequest $request, string $url) => $request->get($url, $query), $route);

        if (! $response->successful()) {
            throw $this->failure($response);
        }

        if (strlen($response->body()) > (int) config('watchdog.woocommerce_api.max_response_bytes')) {
            throw new WooCommerceApiRequestFailed('woocommerce_response_too_large', $response->status());
        }

        $json = $response->json();

        if (! is_array($json)) {
            $protection = StoreProtectionPage::reason($response);
            throw new WooCommerceApiRequestFailed($protection ? 'woocommerce_'.$protection : 'woocommerce_invalid_response', $response->status());
        }

        $total = $response->header('X-WP-Total');
        $pages = $response->header('X-WP-TotalPages');

        return [
            'json' => $json,
            'total' => is_numeric($total) ? (int) $total : null,
            'total_pages' => is_numeric($pages) ? (int) $pages : null,
        ];
    }

    /**
     * Whether the key may write, without writing: WooCommerce checks the key's permission for the method before it
     * looks for a route, and `system_status` has no write route. Read key → 401 authentication error; a key with
     * write permission → 404 no route.
     */
    public function keyCanWrite(string $baseUrl, string $key, string $secret): bool
    {
        $response = $this->send($baseUrl, $key, $secret, fn (PendingRequest $request, string $url) => $request->withBody('{}', 'application/json')->post($url), self::PROBE_ROUTE);
        if ($protection = StoreProtectionPage::reason($response)) {
            throw new WooCommerceApiRequestFailed('woocommerce_'.$protection, $response->status());
        }
        $code = (string) ($response->json('code') ?? '');

        return match (true) {
            $response->status() === 401 && $code === 'woocommerce_rest_authentication_error' => false,
            $response->status() === 404 && $code === 'rest_no_route' => true,
            default => throw new WooCommerceApiRequestFailed('woocommerce_permission_probe_inconclusive', $response->status()),
        };
    }

    public static function allowed(string $method, string $route): bool
    {
        if ($method !== 'GET') {
            return false;
        }

        foreach (self::GET_ALLOWLIST as $pattern) {
            if (preg_match($pattern, $route) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  callable(PendingRequest, string): Response  $call
     */
    private function send(string $baseUrl, string $key, string $secret, callable $call, string $route): Response
    {
        try {
            return $this->http->send(
                $baseUrl,
                '/wp-json'.$route,
                StoreOriginHttp::base((int) config('watchdog.woocommerce_api.timeout_seconds'))->withBasicAuth($key, $secret),
                $call,
                (string) config('watchdog.woocommerce_api.dev_endpoint'),
            );
        } catch (StoreOriginFailure $failure) {
            throw new WooCommerceApiRequestFailed('woocommerce_'.$failure->reasonCode, $failure->httpStatus);
        }
    }

    private function failure(Response $response): WooCommerceApiRequestFailed
    {
        if ($protection = StoreProtectionPage::reason($response)) {
            return new WooCommerceApiRequestFailed('woocommerce_'.$protection, $response->status());
        }

        $code = (string) ($response->json('code') ?? '');
        $retryAfter = $response->header('Retry-After');

        return new WooCommerceApiRequestFailed(match (true) {
            $response->status() === 401 && $code === 'woocommerce_rest_cannot_view' => 'woocommerce_key_permission_missing',
            $response->status() === 401, $response->status() === 403 => 'woocommerce_key_rejected',
            $response->status() === 404 && $code === 'rest_no_route' => 'woocommerce_api_unavailable',
            $response->status() === 404 => 'woocommerce_not_found',
            $response->status() === 429 => 'woocommerce_rate_limited',
            $response->status() >= 500 => 'woocommerce_unavailable',
            default => 'woocommerce_request_rejected',
        }, $response->status(), is_numeric($retryAfter) ? (int) $retryAfter : null);
    }
}
