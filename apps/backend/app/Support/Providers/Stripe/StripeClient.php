<?php

namespace App\Support\Providers\Stripe;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** Read-only Stripe REST calls with the pinned API version; nothing here can write to Stripe. */
class StripeClient
{
    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(string $apiKey, string $path, array $query = []): array
    {
        try {
            $response = Http::baseUrl((string) config('watchdog.stripe.api_base'))
                ->withToken($apiKey)
                ->withHeaders(['Stripe-Version' => (string) config('watchdog.stripe.api_version')])
                ->acceptJson()
                ->timeout((int) config('watchdog.stripe.timeout_seconds'))
                ->get($path, $query);
        } catch (ConnectionException) {
            throw new StripeRequestFailed('stripe_unreachable', 0);
        }

        if (! $response->successful()) {
            throw new StripeRequestFailed($this->errorCode($response), $response->status(), $this->retryAfter($response));
        }

        $json = $response->json();

        return is_array($json) ? $json : throw new StripeRequestFailed('stripe_invalid_response', $response->status());
    }

    /**
     * Every object of a list endpoint created at or after `$createdFrom`, newest first, bounded by pages.
     *
     * @param  array<string, mixed>  $query
     * @return array{objects: list<array<string, mixed>>, complete: bool}
     */
    public function listCreatedSince(string $apiKey, string $path, int $createdFrom, array $query = []): array
    {
        $objects = [];
        $startingAfter = null;
        $maxPages = (int) config('watchdog.stripe.max_pages_per_run');

        for ($page = 0; $page < $maxPages; $page++) {
            $result = $this->get($apiKey, $path, array_filter([
                ...$query,
                'limit' => (int) config('watchdog.stripe.page_size'),
                'created[gte]' => $createdFrom,
                'starting_after' => $startingAfter,
            ], fn (mixed $value): bool => $value !== null));
            $data = is_array($result['data'] ?? null) ? $result['data'] : [];

            foreach ($data as $object) {
                if (is_array($object)) {
                    $objects[] = $object;
                }
            }

            if (($result['has_more'] ?? false) !== true || $data === []) {
                return ['objects' => $objects, 'complete' => true];
            }

            $startingAfter = (string) ($data[array_key_last($data)]['id'] ?? '');
        }

        return ['objects' => $objects, 'complete' => false];
    }

    private function errorCode(Response $response): string
    {
        return match (true) {
            $response->status() === 401 => 'stripe_key_rejected',
            $response->status() === 403 => 'stripe_permission_missing',
            $response->status() === 429 => 'stripe_rate_limited',
            $response->status() >= 500 => 'stripe_unavailable',
            default => 'stripe_request_rejected',
        };
    }

    private function retryAfter(Response $response): ?int
    {
        $value = $response->header('Retry-After');

        return is_numeric($value) ? (int) $value : null;
    }
}
