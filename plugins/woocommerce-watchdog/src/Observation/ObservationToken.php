<?php

namespace BusinessWatchdog\WooCommerce\Observation;

use BusinessWatchdog\WooCommerce\Connection\Connection;

/**
 * Short-lived token printed into an observed page (ADR 0059): only the page kind and an expiry, signed with a key
 * derived from the connection secret, so reports cannot be made up without a rendered page. No visitor identifier.
 */
final class ObservationToken
{
    private const PURPOSE = 'bw-observe-v1';

    public const TTL_SECONDS = 10800;

    public static function issue(string $page, ?int $now = null): ?string
    {
        $key = self::key();

        if ($key === null) {
            return null;
        }

        $payload = rtrim(strtr(base64_encode((string) wp_json_encode(['p' => $page, 'e' => ($now ?? time()) + self::TTL_SECONDS])), '+/', '-_'), '=');

        return $payload . '.' . hash_hmac('sha256', $payload, $key);
    }

    /**
     * @return string|null the page kind of a valid token
     */
    public static function verify(string $token, ?int $now = null): ?string
    {
        $parts = explode('.', $token);
        $key = self::key();

        if (count($parts) !== 2 || strlen($token) > 512 || $key === null || ! hash_equals(hash_hmac('sha256', $parts[0], $key), $parts[1])) {
            return null;
        }

        $payload = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/'), true), true);

        if (! is_array($payload) || ! in_array($payload['p'] ?? null, CheckoutObservation::PAGES, true) || ! is_int($payload['e'] ?? null) || $payload['e'] < ($now ?? time())) {
            return null;
        }

        return $payload['p'];
    }

    private static function key(): ?string
    {
        $connection = Connection::current();
        $secret = $connection !== null ? $connection->secretBytes() : null;

        return $secret === null ? null : hash_hmac('sha256', self::PURPOSE, $secret, true);
    }
}
