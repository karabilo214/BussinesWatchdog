<?php

namespace BusinessWatchdog\WooCommerce\Jobs;

use BusinessWatchdog\WooCommerce\Capture\OrderCapture;
use BusinessWatchdog\WooCommerce\Connection\Connection;
use BusinessWatchdog\WooCommerce\Storage\State;

final class RescanJob
{
    public const HOOK = 'bw_rescan_recent';

    public const INTERVAL_SECONDS = 900;

    public const OVERLAP_SECONDS = 172800;

    public const PAGE_SIZE = 100;

    public const MAX_PAGES = 20;

    public const STATE_LAST = 'last_rescan';

    public static function run(): array
    {
        if (Connection::current() === null || ! State::acquireLock('rescan', 600)) {
            return ['scanned' => 0, 'recorded' => 0];
        }

        $scanned = 0;
        $recorded = 0;
        $since = time() - self::OVERLAP_SECONDS;

        try {
            for ($page = 1; $page <= self::MAX_PAGES; $page++) {
                $ids = wc_get_orders([
                    'type' => 'shop_order',
                    'date_modified' => '>' . $since,
                    'orderby' => 'ID',
                    'order' => 'ASC',
                    'limit' => self::PAGE_SIZE,
                    'paged' => $page,
                    'return' => 'ids',
                ]);

                foreach ((array) $ids as $id) {
                    $scanned++;

                    try {
                        $recorded += OrderCapture::captureById((int) $id);
                    } catch (\Throwable $exception) {
                        OrderCapture::reportError((int) $id, $exception);
                    }
                }

                if (count((array) $ids) < self::PAGE_SIZE) {
                    break;
                }
            }
        } finally {
            State::releaseLock('rescan');
        }

        State::set(self::STATE_LAST, ['at' => gmdate('c'), 'scanned' => $scanned, 'recorded' => $recorded]);

        return ['scanned' => $scanned, 'recorded' => $recorded];
    }
}
