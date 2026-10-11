<?php

namespace App\Support\Providers\WooCommerce;

use App\Support\Network\DnsClient;
use App\Support\Network\PublicDestinationPolicy;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

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
        private readonly DnsClient $dns,
        private readonly PublicDestinationPolicy $policy,
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
            throw new WooCommerceApiRequestFailed('woocommerce_invalid_response', $response->status());
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
        $host = strtolower((string) parse_url($baseUrl, PHP_URL_HOST));
        $request = Http::withBasicAuth($key, $secret)
            ->acceptJson()
            ->withHeaders(['User-Agent' => 'BusinessWatchdog-Connector/1'])
            ->timeout((int) config('watchdog.woocommerce_api.timeout_seconds'))
            ->connectTimeout(10);
        $devEndpoint = (string) config('watchdog.woocommerce_api.dev_endpoint');

        if ($devEndpoint !== '' && app()->environment(['local', 'testing'])) {
            $url = rtrim($devEndpoint, '/').'/wp-json'.$route;
            $request = $request->withHeaders(['Host' => $host, 'X-Forwarded-Proto' => 'https'])->withOptions(['allow_redirects' => false]);
        } else {
            if (parse_url($baseUrl, PHP_URL_SCHEME) !== 'https' || ! $this->policy->isAllowedUrl($baseUrl)) {
                throw new WooCommerceApiRequestFailed('woocommerce_store_url_not_allowed');
            }

            $ips = filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false ? [trim($host, '[]')] : $this->dns->resolveIps($host);

            if ($ips === []) {
                throw new WooCommerceApiRequestFailed('woocommerce_unreachable');
            }

            foreach ($ips as $ip) {
                if (! $this->policy->isPublicIp($ip)) {
                    throw new WooCommerceApiRequestFailed('woocommerce_store_url_not_allowed');
                }
            }

            $pinned = str_contains($ips[0], ':') ? '['.$ips[0].']' : $ips[0];
            $url = rtrim($baseUrl, '/').'/wp-json'.$route;
            $request = $request->withOptions([
                'allow_redirects' => false,
                'protocols' => ['https'],
                'curl' => [CURLOPT_RESOLVE => [$host.':443:'.$pinned]],
            ]);
        }

        try {
            $response = $call($request, $url);
        } catch (ConnectionException) {
            throw new WooCommerceApiRequestFailed('woocommerce_unreachable');
        }

        if ($response->redirect()) {
            throw new WooCommerceApiRequestFailed('woocommerce_redirected', $response->status());
        }

        return $response;
    }

    private function failure(Response $response): WooCommerceApiRequestFailed
    {
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
