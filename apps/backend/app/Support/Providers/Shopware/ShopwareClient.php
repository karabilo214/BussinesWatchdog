<?php

namespace App\Support\Providers\Shopware;

use App\Support\Network\StoreOriginFailure;
use App\Support\Network\StoreOriginHttp;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Shopware Admin API with an Integration key (ADR 0025/0026). Only the OAuth token, order searches and the app's own
 * checkout observation entities (ADR 0062) leave the service — never the state-transition routes, which Shopware does
 * not protect for read-only roles, and never a write to the shop's own data. Requests go to the store's own public
 * HTTPS origin through StoreOriginHttp.
 */
class ShopwareClient
{
    public const TOKEN_PATH = '/api/oauth/token';

    public const REPORT_ENTITY = 'custom_entity_bw_checkout_report';

    public const SETTING_ENTITY = 'custom_entity_bw_checkout_setting';

    public const REPORT_SEARCH_PATH = '/api/search/custom-entity-bw-checkout-report';

    public const SYNC_PATH = '/api/_action/sync';

    private const POST_ALLOWLIST = [self::TOKEN_PATH, '/api/search/order', '/api/search-ids/order', self::REPORT_SEARCH_PATH, self::SYNC_PATH];

    public function __construct(
        private readonly StoreOriginHttp $http,
    ) {}

    /**
     * @return array{token: string, expires_in: int}
     */
    public function token(string $baseUrl, string $accessKey, string $secret, bool $fresh = false): array
    {
        $cacheKey = 'shopware:token:'.hash('sha256', $baseUrl.'|'.$accessKey.'|'.$secret);
        $known = $fresh ? null : Cache::get($cacheKey);

        if (is_array($known) && is_string($known['token'] ?? null)) {
            return $known;
        }

        $response = $this->post($baseUrl, self::TOKEN_PATH, ['grant_type' => 'client_credentials', 'client_id' => $accessKey, 'client_secret' => $secret]);
        $token = $response->json('access_token');
        $expiresIn = (int) ($response->json('expires_in') ?? 600);

        if (! is_string($token) || $token === '') {
            throw new ShopwareRequestFailed('shopware_invalid_response', $response->status());
        }

        $result = ['token' => $token, 'expires_in' => $expiresIn];
        Cache::put($cacheKey, $result, Carbon::now()->addSeconds(max(30, $expiresIn - 60)));

        return $result;
    }

    /**
     * @param  array<string, mixed>  $criteria
     * @return array<string, mixed>
     */
    public function search(string $baseUrl, string $token, string $path, array $criteria): array
    {
        $json = $this->post($baseUrl, $path, $criteria, $token)->json();

        return is_array($json) ? $json : throw new ShopwareRequestFailed('shopware_invalid_response');
    }

    /**
     * Writes through the sync API, limited to the app's own observation entities: the observe setting and deleting
     * collected reports. Anything else is refused before a request is made.
     *
     * @param  list<array{action: string, entity: string, payload: list<array<string, mixed>>}>  $operations
     */
    public function syncOwnEntities(string $baseUrl, string $token, array $operations): void
    {
        foreach ($operations as $operation) {
            $allowed = match ($operation['entity'] ?? null) {
                self::SETTING_ENTITY => ($operation['action'] ?? null) === 'upsert',
                self::REPORT_ENTITY => ($operation['action'] ?? null) === 'delete',
                default => false,
            };

            if (! $allowed) {
                throw new ShopwareRequestFailed('shopware_endpoint_not_allowed');
            }
        }

        $this->post($baseUrl, self::SYNC_PATH, $operations, $token);
    }

    public static function allowed(string $method, string $path): bool
    {
        return $method === 'POST' && in_array($path, self::POST_ALLOWLIST, true);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function post(string $baseUrl, string $path, array $body, ?string $token = null): Response
    {
        if (! self::allowed('POST', $path)) {
            throw new ShopwareRequestFailed('shopware_endpoint_not_allowed');
        }

        $request = StoreOriginHttp::base((int) config('watchdog.shopware.timeout_seconds'))->asJson();

        if ($token !== null) {
            $request = $request->withToken($token);
        }

        try {
            $response = $this->http->send($baseUrl, $path, $request, fn (PendingRequest $pending, string $url) => $pending->post($url, $body), (string) config('watchdog.shopware.dev_endpoint'));
        } catch (StoreOriginFailure $failure) {
            throw new ShopwareRequestFailed('shopware_'.$failure->reasonCode, $failure->httpStatus);
        }

        if ($response->successful()) {
            if (strlen($response->body()) > (int) config('watchdog.shopware.max_response_bytes')) {
                throw new ShopwareRequestFailed('shopware_response_too_large', $response->status());
            }

            return $response;
        }

        $code = (string) ($response->json('errors.0.code') ?? '');
        $retryAfter = $response->header('Retry-After');

        throw new ShopwareRequestFailed(match (true) {
            $path === self::TOKEN_PATH && in_array($response->status(), [400, 401], true) => 'shopware_key_rejected',
            $response->status() === 401 => 'shopware_key_rejected',
            $response->status() === 403 && $code === 'FRAMEWORK__MISSING_PRIVILEGE_ERROR' => 'shopware_order_read_missing',
            $response->status() === 403 => 'shopware_key_rejected',
            $response->status() === 404 => 'shopware_api_unavailable',
            $response->status() === 429 => 'shopware_rate_limited',
            $response->status() >= 500 => 'shopware_unavailable',
            default => 'shopware_request_rejected',
        }, $response->status(), is_numeric($retryAfter) ? (int) $retryAfter : null);
    }
}
