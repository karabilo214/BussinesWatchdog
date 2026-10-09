<?php

namespace BusinessWatchdog\WooCommerce\Synthetic;

use BusinessWatchdog\WooCommerce\Connection\Connection;

final class SyntheticRequest
{
    public const HEADER = 'HTTP_X_BW_SYNTHETIC';

    public const META_KEY = '_bw_synthetic_run';

    private const PURPOSE = 'bw-synthetic-v1';

    private static $resolved = false;

    private static $current = null;

    /**
     * The verified marker of a Business Watchdog browser check, or null. A header alone
     * grants nothing: the token must be signed with a key derived from this store's own
     * connection secret, bound to this connection and not expired.
     */
    public static function current(): ?array
    {
        if (! self::$resolved) {
            self::$resolved = true;
            self::$current = self::verify(isset($_SERVER[self::HEADER]) ? (string) $_SERVER[self::HEADER] : '');
        }

        return self::$current;
    }

    public static function verify(string $token, ?int $now = null): ?array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3 || $parts[0] !== 'v1' || strlen($token) > 1024) {
            return null;
        }

        $connection = Connection::current();
        $secret = $connection !== null ? $connection->secretBytes() : null;

        if ($secret === null) {
            return null;
        }

        $key = hash_hmac('sha256', self::PURPOSE, $secret, true);

        if (! hash_equals(hash_hmac('sha256', 'v1.' . $parts[1], $key), $parts[2])) {
            return null;
        }

        $payload = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true);

        if (! is_array($payload)
            || ($payload['i'] ?? null) !== $connection->integrationId()
            || ($payload['k'] ?? null) !== $connection->keyId()
            || ! is_int($payload['e'] ?? null)
            || $payload['e'] < ($now ?? time())
            || ! is_string($payload['r'] ?? null)
            || ! preg_match('/^[0-9a-f-]{36}$/', $payload['r'])) {
            return null;
        }

        return ['run_id' => $payload['r'], 'expires_at' => $payload['e']];
    }

    public static function reset(): void
    {
        self::$resolved = false;
        self::$current = null;
    }
}
