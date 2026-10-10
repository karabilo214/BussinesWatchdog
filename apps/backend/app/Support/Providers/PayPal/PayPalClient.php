<?php

namespace App\Support\Providers\PayPal;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * PayPal credentials always carry write permissions (refunds, captures; ADR 0022), so the adapter is limited here:
 * the only POSTs are the OAuth token and the webhook signature check, and GETs go only to the read endpoints below.
 * Anything else is refused before a request leaves the service.
 */
class PayPalClient
{
    /** @var list<string> */
    public const GET_ALLOWLIST = [
        '#^/v1/reporting/transactions$#',
        '#^/v2/checkout/orders/[A-Z0-9]{1,36}$#',
        '#^/v2/payments/captures/[A-Z0-9]{1,36}$#',
        '#^/v2/payments/refunds/[A-Z0-9]{1,36}$#',
        '#^/v2/payments/authorizations/[A-Z0-9]{1,36}$#',
    ];

    public const TOKEN_PATH = '/v1/oauth2/token';

    public const VERIFY_WEBHOOK_PATH = '/v1/notifications/verify-webhook-signature';

    /** @var array<string, array{token: string, expires_at: int, scopes: list<string>}> */
    private array $tokens = [];

    /**
     * @return array{token: string, scopes: list<string>, app_id: ?string}
     */
    public function token(string $mode, string $clientId, string $clientSecret, bool $fresh = false): array
    {
        $key = hash('sha256', $mode.'|'.$clientId.'|'.$clientSecret);

        if (! $fresh && isset($this->tokens[$key]) && $this->tokens[$key]['expires_at'] > time() + 60) {
            return ['token' => $this->tokens[$key]['token'], 'scopes' => $this->tokens[$key]['scopes'], 'app_id' => null];
        }

        $response = $this->send(fn () => Http::baseUrl($this->base($mode))
            ->withBasicAuth($clientId, $clientSecret)
            ->asForm()
            ->acceptJson()
            ->timeout((int) config('watchdog.paypal.timeout_seconds'))
            ->post(self::TOKEN_PATH, ['grant_type' => 'client_credentials']));
        $json = $response->json();

        if (! is_array($json) || ! is_string($json['access_token'] ?? null)) {
            throw new PayPalRequestFailed('paypal_invalid_response', $response->status());
        }

        $scopes = array_values(array_filter(explode(' ', (string) ($json['scope'] ?? ''))));
        $this->tokens[$key] = ['token' => $json['access_token'], 'expires_at' => time() + (int) ($json['expires_in'] ?? 0), 'scopes' => $scopes];

        return ['token' => $json['access_token'], 'scopes' => $scopes, 'app_id' => is_string($json['app_id'] ?? null) ? $json['app_id'] : null];
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(string $mode, string $token, string $path, array $query = []): array
    {
        if (! self::allowed('GET', $path)) {
            throw new PayPalRequestFailed('paypal_endpoint_not_allowed', 0);
        }

        $response = $this->send(fn () => Http::baseUrl($this->base($mode))
            ->withToken($token)
            ->acceptJson()
            ->timeout((int) config('watchdog.paypal.timeout_seconds'))
            ->get($path, $query));
        $json = $response->json();

        return is_array($json) ? $json : throw new PayPalRequestFailed('paypal_invalid_response', $response->status());
    }

    /**
     * Asks PayPal whether a webhook delivery is genuine. The event is spliced in as the raw body so its bytes are not
     * re-encoded (re-encoding breaks the signature check).
     *
     * @param  array<string, string>  $headers
     */
    public function verifyWebhook(string $mode, string $token, string $webhookId, array $headers, string $rawEvent): bool
    {
        $envelope = json_encode([
            'auth_algo' => $headers['auth_algo'],
            'cert_url' => $headers['cert_url'],
            'transmission_id' => $headers['transmission_id'],
            'transmission_sig' => $headers['transmission_sig'],
            'transmission_time' => $headers['transmission_time'],
            'webhook_id' => $webhookId,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $body = substr($envelope, 0, -1).',"webhook_event":'.$rawEvent.'}';

        $response = $this->send(fn () => Http::baseUrl($this->base($mode))
            ->withToken($token)
            ->acceptJson()
            ->withBody($body, 'application/json')
            ->timeout((int) config('watchdog.paypal.timeout_seconds'))
            ->post(self::VERIFY_WEBHOOK_PATH));

        return ($response->json('verification_status') ?? null) === 'SUCCESS';
    }

    public static function allowed(string $method, string $path): bool
    {
        if ($method === 'POST') {
            return in_array($path, [self::TOKEN_PATH, self::VERIFY_WEBHOOK_PATH], true);
        }

        if ($method !== 'GET') {
            return false;
        }

        foreach (self::GET_ALLOWLIST as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return true;
            }
        }

        return false;
    }

    private function base(string $mode): string
    {
        return (string) config($mode === 'live' ? 'watchdog.paypal.api_base_live' : 'watchdog.paypal.api_base_sandbox');
    }

    /**
     * @param  callable(): Response  $request
     */
    private function send(callable $request): Response
    {
        try {
            $response = $request();
        } catch (ConnectionException) {
            throw new PayPalRequestFailed('paypal_unreachable', 0);
        }

        if (! $response->successful()) {
            $retryAfter = $response->header('Retry-After');

            throw new PayPalRequestFailed(match (true) {
                $response->status() === 401 => 'paypal_credentials_rejected',
                $response->status() === 403 => 'paypal_permission_missing',
                $response->status() === 404 => 'paypal_not_found',
                $response->status() === 429 => 'paypal_rate_limited',
                $response->status() >= 500 => 'paypal_unavailable',
                default => 'paypal_request_rejected',
            }, $response->status(), is_numeric($retryAfter) ? (int) $retryAfter : null);
        }

        return $response;
    }
}
