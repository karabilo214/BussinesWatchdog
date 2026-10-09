<?php

namespace BusinessWatchdog\WooCommerce\Jobs;

use BusinessWatchdog\WooCommerce\Connection\Connection;
use BusinessWatchdog\WooCommerce\Http\SignedClient;
use BusinessWatchdog\WooCommerce\Storage\Outbox;
use BusinessWatchdog\WooCommerce\Storage\State;

final class DeliveryJob
{
    public const HOOK = 'bw_deliver_outbox';

    public const INTERVAL_SECONDS = 60;

    public const MAX_EVENTS = 100;

    public const MAX_BYTES = 1048576;

    public const MAX_BATCHES_PER_RUN = 20;

    public const LOCK_SECONDS = 300;

    public const STATE_LAST = 'last_delivery';

    private const DONE_STATUSES = ['accepted', 'duplicate'];

    public static function run(): array
    {
        $connection = Connection::current();

        if ($connection === null || $connection->status() !== Connection::STATUS_CONNECTED) {
            return ['batches' => 0, 'reason' => 'not_connected'];
        }

        if (! self::acquireLock()) {
            return ['batches' => 0, 'reason' => 'locked'];
        }

        $totals = ['batches' => 0, 'delivered' => 0, 'dead_letter' => 0, 'retry' => 0];

        try {
            for ($i = 0; $i < self::MAX_BATCHES_PER_RUN; $i++) {
                $rows = Outbox::due(self::MAX_EVENTS, self::MAX_BYTES);

                if ($rows === []) {
                    break;
                }

                $outcome = self::sendBatch($connection, $rows);
                $totals['batches']++;

                foreach (['delivered', 'dead_letter', 'retry'] as $key) {
                    $totals[$key] += $outcome[$key];
                }

                if ($outcome['stop']) {
                    $totals['stopped'] = $outcome['code'];
                    break;
                }
            }
        } finally {
            self::releaseLock();
        }

        State::set(self::STATE_LAST, array_merge(['at' => gmdate('c')], $totals));

        return $totals;
    }

    private static function sendBatch(Connection $connection, array $rows): array
    {
        $events = array_map(static function (array $row) {
            return json_decode((string) $row['payload']);
        }, $rows);

        $response = (new SignedClient($connection))->post('/api/v1/ingest/events', ['events' => $events]);
        $outcome = ['delivered' => 0, 'dead_letter' => 0, 'retry' => 0, 'stop' => false, 'code' => null];

        if ($response->status === 202 || $response->status === 207) {
            return self::applyResults($rows, (array) ($response->json['results'] ?? []), $outcome);
        }

        if ($response->status === 401 || $response->status === 403) {
            if (in_array($response->errorCode, ['credential_revoked', 'signature_invalid'], true) || $response->status === 403) {
                $connection->suspend((string) ($response->errorCode ?? 'forbidden'));
            }

            Outbox::retryLater($rows, (string) ($response->errorCode ?? 'unauthorized'), null);

            return array_merge($outcome, ['retry' => count($rows), 'stop' => true, 'code' => $response->errorCode ?? 'unauthorized']);
        }

        if ($response->status === 413 && count($rows) > 1) {
            $half = array_slice($rows, 0, (int) ceil(count($rows) / 2));
            $result = self::sendBatch($connection, $half);

            return array_merge($result, ['stop' => true]);
        }

        if ($response->status === 422 || $response->status === 413) {
            Outbox::deadLetter(array_column($rows, 'id'), (string) ($response->errorCode ?? 'batch_rejected'));

            return array_merge($outcome, ['dead_letter' => count($rows), 'stop' => true, 'code' => $response->errorCode]);
        }

        Outbox::retryLater($rows, (string) ($response->errorCode ?? ('http_' . $response->status)), $response->status === 429 ? $response->retryAfterSeconds : null);

        return array_merge($outcome, ['retry' => count($rows), 'stop' => true, 'code' => $response->errorCode ?? ('http_' . $response->status)]);
    }

    private static function applyResults(array $rows, array $results, array $outcome): array
    {
        $byIndex = [];

        foreach ($results as $result) {
            if (is_array($result) && isset($result['index'])) {
                $byIndex[(int) $result['index']] = $result;
            }
        }

        $done = [];
        $missing = [];

        foreach (array_values($rows) as $index => $row) {
            $result = $byIndex[$index] ?? null;

            if ($result === null || ($result['event_id'] ?? $row['event_id']) !== $row['event_id']) {
                $missing[] = $row;
                continue;
            }

            if (in_array($result['status'] ?? null, self::DONE_STATUSES, true)) {
                $done[] = (int) $row['id'];
                continue;
            }

            Outbox::deadLetter([(int) $row['id']], (string) (($result['status'] ?? 'rejected') . ':' . ($result['code'] ?? 'unknown')));
            $outcome['dead_letter']++;
        }

        Outbox::deleteByIds($done);
        $outcome['delivered'] = count($done);

        if ($missing !== []) {
            Outbox::retryLater($missing, 'result_missing', null);
            $outcome['retry'] += count($missing);
        }

        return $outcome;
    }

    private static function acquireLock(): bool
    {
        return State::acquireLock('delivery', self::LOCK_SECONDS);
    }

    private static function releaseLock(): void
    {
        State::releaseLock('delivery');
    }
}
