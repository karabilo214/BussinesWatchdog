<?php

namespace BusinessWatchdog\WooCommerce\Jobs;

use BusinessWatchdog\WooCommerce\Capture\EventFactory;
use BusinessWatchdog\WooCommerce\Connection\Connection;
use BusinessWatchdog\WooCommerce\Observation\ObservationLog;
use BusinessWatchdog\WooCommerce\Storage\Outbox;
use BusinessWatchdog\WooCommerce\Storage\State;

/**
 * Sends closed checkout observation windows (ADR 0059) as `checkout.observation` events through the outbox.
 */
final class ObservationReportJob
{
    public const HOOK = 'bw_checkout_observation';

    public const INTERVAL_SECONDS = 60;

    public const RETENTION_DAYS = 7;

    public static function run(?int $now = null): int
    {
        if (Connection::current() === null || ! State::acquireLock('checkout_observation', 300)) {
            return 0;
        }

        try {
            $now = $now ?? time();
            $sent = 0;

            foreach (ObservationLog::unreportedClosed($now, 200) as $row) {
                $start = strtotime($row['window_start'] . ' UTC');
                $data = ['window_start' => gmdate('Y-m-d\TH:i:s\Z', $start), 'window_end' => gmdate('Y-m-d\TH:i:s\Z', $start + PaymentAttemptsJob::WINDOW_SECONDS)];

                foreach (ObservationLog::COUNTERS as $counter) {
                    $data[$counter] = (int) $row[$counter];
                }

                $aggregateId = 'observation:' . $data['window_start'];
                Outbox::enqueue(EventFactory::envelope('checkout.observation', 'checkout', $aggregateId, (int) $row['revision'], $data['window_end'], $data), 'checkout_observation:' . $data['window_start']);
                ObservationLog::markReported((string) $row['window_start'], (int) $row['revision']);
                $sent++;
            }

            ObservationLog::prune(self::RETENTION_DAYS);

            return $sent;
        } finally {
            State::releaseLock('checkout_observation');
        }
    }
}
