<?php

namespace App\Support\Billing\Paddle;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Paddle Billing REST calls with the pinned API version. Response bodies are never logged or stored as they are; callers
 * keep only the fields they need.
 */
class PaddleClient
{
    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        return $this->send(fn (PendingRequest $request): Response => $request->get($path, $query));
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function post(string $path, array $body): array
    {
        return $this->send(fn (PendingRequest $request): Response => $request->post($path, $body));
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function patch(string $path, array $body): array
    {
        return $this->send(fn (PendingRequest $request): Response => $request->patch($path, $body));
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     * @return array<string, mixed>
     */
    private function send(callable $call): array
    {
        if (! PaddleConfig::configured()) {
            throw new PaddleRequestFailed('paddle_not_configured', 0);
        }

        try {
            $response = $call(Http::baseUrl(PaddleConfig::apiBase())
                ->withToken(PaddleConfig::apiKey())
                ->withHeaders(['Paddle-Version' => (string) config('watchdog.billing.paddle.api_version')])
                ->acceptJson()
                ->asJson()
                ->timeout((int) config('watchdog.billing.paddle.timeout_seconds')));
        } catch (ConnectionException) {
            throw new PaddleRequestFailed('paddle_unreachable', 0);
        }

        $json = $response->json();

        if (! $response->successful()) {
            $code = is_array($json) && is_string($json['error']['code'] ?? null) ? $json['error']['code'] : null;

            throw new PaddleRequestFailed($this->errorCode($response), $response->status(), $code);
        }

        return is_array($json) && is_array($json['data'] ?? null)
            ? $json
            : throw new PaddleRequestFailed('paddle_invalid_response', $response->status());
    }

    private function errorCode(Response $response): string
    {
        return match (true) {
            $response->status() === 401 => 'paddle_key_rejected',
            $response->status() === 403 => 'paddle_permission_missing',
            $response->status() === 404 => 'paddle_not_found',
            $response->status() === 429 => 'paddle_rate_limited',
            $response->status() >= 500 => 'paddle_unavailable',
            default => 'paddle_request_rejected',
        };
    }
}
