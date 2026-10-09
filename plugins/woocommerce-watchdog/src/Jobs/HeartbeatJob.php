<?php

namespace BusinessWatchdog\WooCommerce\Jobs;

use BusinessWatchdog\WooCommerce\Connection\Connection;
use BusinessWatchdog\WooCommerce\Http\Response;
use BusinessWatchdog\WooCommerce\Http\SignedClient;
use BusinessWatchdog\WooCommerce\Storage\OutboxStats;
use BusinessWatchdog\WooCommerce\Storage\State;

final class HeartbeatJob
{
    public const HOOK = 'bw_heartbeat';

    public const INTERVAL_SECONDS = 300;

    public const STATE_LAST = 'last_heartbeat';

    public const STATE_PENDING_CHALLENGE = 'pending_store_verification';

    private const SUSPENDING_CODES = ['credential_revoked', 'signature_invalid'];

    public static function run(): array
    {
        $connection = Connection::current();

        if ($connection === null || $connection->status() !== Connection::STATUS_CONNECTED) {
            return ['sent' => false, 'reason' => 'not_connected'];
        }

        $response = (new SignedClient($connection))->post('/api/v1/ingest/heartbeat', [
            'backlog_count' => OutboxStats::backlogCount(),
            'oldest_pending_at' => OutboxStats::oldestPendingAt(),
            'plugin_version' => BW_PLUGIN_VERSION,
        ]);

        State::set(self::STATE_LAST, [
            'at' => gmdate('c'),
            'status' => $response->status,
            'code' => $response->errorCode,
        ]);

        if (self::requiresReconnect($response)) {
            $connection->suspend((string) $response->errorCode);

            return ['sent' => true, 'status' => $response->status, 'suspended' => true];
        }

        if (! $response->successful() || ! is_array($response->json)) {
            return ['sent' => true, 'status' => $response->status];
        }

        self::rememberChallenge($response->json['store_verification'] ?? null);

        $rotated = false;

        if (($response->json['credential_rotation_requested'] ?? false) === true) {
            $rotated = self::rotate($connection);
        }

        return ['sent' => true, 'status' => $response->status, 'rotated' => $rotated];
    }

    public static function rotate(Connection $connection): bool
    {
        $response = (new SignedClient($connection))->post('/api/v1/ingest/credentials/rotate', []);

        if ($response->status !== 201 || ! isset($response->json['key_id'], $response->json['secret'])) {
            return false;
        }

        $connection->replaceKey((string) $response->json['key_id'], (string) $response->json['secret']);
        $confirmation = (new SignedClient($connection))->post('/api/v1/ingest/heartbeat', [
            'backlog_count' => OutboxStats::backlogCount(),
            'plugin_version' => BW_PLUGIN_VERSION,
        ]);

        return $confirmation->successful();
    }

    private static function requiresReconnect(Response $response): bool
    {
        return ($response->status === 401 && in_array($response->errorCode, self::SUSPENDING_CODES, true))
            || $response->status === 403;
    }

    private static function rememberChallenge($verification): void
    {
        if (! is_array($verification) || ! isset($verification['id'], $verification['challenge'])) {
            State::delete(self::STATE_PENDING_CHALLENGE);

            return;
        }

        State::set(self::STATE_PENDING_CHALLENGE, [
            'id' => (string) $verification['id'],
            'challenge' => (string) $verification['challenge'],
        ]);
    }
}
