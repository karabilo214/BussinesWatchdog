<?php

namespace BusinessWatchdog\WooCommerce\Jobs;

use BusinessWatchdog\WooCommerce\Capture\OrderCapture;
use BusinessWatchdog\WooCommerce\Connection\Connection;
use BusinessWatchdog\WooCommerce\Storage\State;

final class BackfillJob
{
    public const HOOK = 'bw_backfill';

    public const AUDIT_HOOK = 'bw_daily_audit';

    public const INTERVAL_SECONDS = 60;

    public const AUDIT_INTERVAL_SECONDS = 86400;

    public const WINDOW_DAYS = 90;

    public const PAGE_SIZE = 100;

    public const PAGES_PER_RUN = 5;

    public const STATE = 'backfill';

    public static function start(string $reason): array
    {
        $state = [
            'status' => 'running',
            'reason' => $reason,
            'window_start' => time() - self::WINDOW_DAYS * 86400,
            'next_page' => 1,
            'scanned' => 0,
            'recorded' => 0,
            'started_at' => gmdate('c'),
            'finished_at' => null,
        ];
        State::set(self::STATE, $state);

        return $state;
    }

    public static function cancel(): void
    {
        $state = State::get(self::STATE);

        if (is_array($state) && ($state['status'] ?? null) === 'running') {
            $state['status'] = 'cancelled';
            $state['finished_at'] = gmdate('c');
            State::set(self::STATE, $state);
        }
    }

    public static function dailyAudit(): void
    {
        $state = State::get(self::STATE);

        if (Connection::current() !== null && (! is_array($state) || ($state['status'] ?? null) !== 'running')) {
            self::start('daily_audit');
        }
    }

    public static function run(int $pagesPerRun = self::PAGES_PER_RUN, bool $pace = true): array
    {
        $state = State::get(self::STATE);

        if (! is_array($state) || ($state['status'] ?? null) !== 'running' || Connection::current() === null) {
            return is_array($state) ? $state : ['status' => 'idle'];
        }

        if (! State::acquireLock('backfill', 600)) {
            return $state;
        }

        try {
            for ($i = 0; $i < $pagesPerRun; $i++) {
                $ids = (array) wc_get_orders([
                    'type' => 'shop_order',
                    'date_created' => '>' . (int) $state['window_start'],
                    'orderby' => 'ID',
                    'order' => 'ASC',
                    'limit' => self::PAGE_SIZE,
                    'paged' => (int) $state['next_page'],
                    'return' => 'ids',
                ]);

                foreach ($ids as $id) {
                    $state['scanned']++;

                    try {
                        $state['recorded'] += OrderCapture::captureById((int) $id);
                    } catch (\Throwable $exception) {
                        OrderCapture::reportError((int) $id, $exception);
                    }
                }

                $state['next_page']++;

                if (count($ids) < self::PAGE_SIZE) {
                    $state['status'] = 'completed';
                    $state['finished_at'] = gmdate('c');
                    break;
                }

                State::set(self::STATE, $state);

                if ($pace) {
                    sleep(1);
                }
            }
        } finally {
            State::set(self::STATE, $state);
            State::releaseLock('backfill');
        }

        return $state;
    }
}
