<?php

namespace BusinessWatchdog\WooCommerce\Jobs;

use BusinessWatchdog\WooCommerce\Attempts\AttemptLog;
use BusinessWatchdog\WooCommerce\Capture\EventFactory;
use BusinessWatchdog\WooCommerce\Connection\Connection;
use BusinessWatchdog\WooCommerce\Storage\Outbox;
use BusinessWatchdog\WooCommerce\Storage\State;

final class PaymentAttemptsJob
{
    public const HOOK = 'bw_payment_attempts';

    public const INTERVAL_SECONDS = 60;

    public const WINDOW_SECONDS = 300;

    public const PENDING_TIMEOUT_SECONDS = 1800;

    public const RECHECK_AFTER_SECONDS = 120;

    public const RETENTION_DAYS = 7;

    public const BATCH = 1000;

    public static function run(?int $now = null): array
    {
        if (Connection::current() === null || ! State::acquireLock('payment_attempts', 300)) {
            return ['resolved' => 0, 'windows' => 0];
        }

        try {
            $now = $now ?? time();
            $resolved = self::resolveOpen($now);
            $windows = self::reportClosedWindows($now);
            AttemptLog::prune(self::RETENTION_DAYS);

            return ['resolved' => $resolved, 'windows' => $windows];
        } finally {
            State::releaseLock('payment_attempts');
        }
    }

    public static function windowStart(int $timestamp): int
    {
        return intdiv($timestamp, self::WINDOW_SECONDS) * self::WINDOW_SECONDS;
    }

    private static function resolveOpen(int $now): int
    {
        $resolved = 0;

        foreach (AttemptLog::openBefore($now - self::RECHECK_AFTER_SECONDS, 200) as $row) {
            $orderId = (int) $row['order_id'];
            $order = wc_get_order($orderId);

            if (! $order instanceof \WC_Order) {
                $resolved += AttemptLog::resolveOpen($orderId, AttemptLog::FAILED, 'order_missing');
            } elseif ($order->has_status(wc_get_is_paid_statuses())) {
                $resolved += AttemptLog::resolveOpen($orderId, AttemptLog::PAID, null);
            } elseif ($order->has_status('on-hold')) {
                $resolved += AttemptLog::resolveOpen($orderId, AttemptLog::ON_HOLD, null);
            } elseif ($order->has_status('failed')) {
                $resolved += AttemptLog::resolveOpen($orderId, AttemptLog::FAILED, 'status_failed');
            } elseif ($order->has_status('cancelled')) {
                $resolved += AttemptLog::resolveOpen($orderId, AttemptLog::FAILED, 'cancelled');
            } elseif (strtotime($row['attempted_at'] . ' UTC') <= $now - self::PENDING_TIMEOUT_SECONDS) {
                $resolved += AttemptLog::resolveOpen($orderId, AttemptLog::PENDING_STUCK, 'no_result');
            }
        }

        return $resolved;
    }

    private static function reportClosedWindows(int $now): int
    {
        global $wpdb;

        $rows = AttemptLog::unreportedBefore(gmdate('Y-m-d H:i:s', self::windowStart($now)), self::BATCH);

        if ($rows === []) {
            return 0;
        }

        $windows = [];

        foreach ($rows as $row) {
            $windows[self::windowStart((int) strtotime($row['resolved_at'] . ' UTC'))][] = $row;
        }

        ksort($windows);
        $recorded = 0;

        foreach ($windows as $start => $windowRows) {
            $data = self::windowData((int) $start, $windowRows);
            $windowStart = $data['window_start'];
            $envelope = EventFactory::envelope('checkout.payment_attempts', 'checkout', $windowStart, 1, $data['window_end'], $data);

            $wpdb->query('START TRANSACTION');

            try {
                Outbox::enqueue($envelope, 'payment_attempts:' . $windowStart);
                AttemptLog::markReported(array_column($windowRows, 'id'));
                $wpdb->query('COMMIT');
                $recorded++;
            } catch (\Throwable $exception) {
                $wpdb->query('ROLLBACK');

                throw $exception;
            }
        }

        return $recorded;
    }

    private static function windowData(int $start, array $rows): array
    {
        $methods = [];

        foreach ($rows as $row) {
            $method = (string) $row['payment_method'];
            $outcome = (string) $row['outcome'];

            if (! isset($methods[$method])) {
                $methods[$method] = [
                    'payment_method' => $method,
                    'paid' => 0,
                    'on_hold' => 0,
                    'failed' => 0,
                    'pending_stuck' => 0,
                    'late_success' => 0,
                    'rejected_before_order' => 0,
                    'trailing_failures' => 0,
                    'failure_classes' => [],
                ];
            }

            if (! array_key_exists($outcome, $methods[$method]) || $outcome === 'trailing_failures') {
                continue;
            }

            $methods[$method][$outcome]++;

            if (in_array($outcome, AttemptLog::SUCCESS_OUTCOMES, true)) {
                $methods[$method]['trailing_failures'] = 0;
            } elseif (in_array($outcome, AttemptLog::FAILURE_OUTCOMES, true)) {
                $methods[$method]['trailing_failures']++;
                $class = (string) $row['failure_class'] !== '' ? (string) $row['failure_class'] : 'unknown';
                $methods[$method]['failure_classes'][$class] = ($methods[$method]['failure_classes'][$class] ?? 0) + 1;
            }
        }

        ksort($methods);

        foreach ($methods as &$method) {
            ksort($method['failure_classes']);
            $method['failure_classes'] = (object) $method['failure_classes'];
        }

        unset($method);

        return [
            'window_start' => gmdate('Y-m-d\TH:i:s\Z', $start),
            'window_end' => gmdate('Y-m-d\TH:i:s\Z', $start + self::WINDOW_SECONDS),
            'methods' => array_values($methods),
        ];
    }
}
