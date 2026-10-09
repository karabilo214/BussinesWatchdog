<?php

namespace BusinessWatchdog\WooCommerce\Compat;

final class WpCronAdapter implements Scheduler
{
    public const SCHEDULE_PREFIX = 'bw_every_';

    public static function registerSchedules(array $schedules): array
    {
        foreach ([60, 300, 900] as $seconds) {
            $schedules[self::SCHEDULE_PREFIX . $seconds] = [
                'interval' => $seconds,
                'display' => sprintf('Business Watchdog every %d seconds', $seconds),
            ];
        }

        return $schedules;
    }

    public function ensureRecurring(string $hook, int $intervalSeconds): void
    {
        if (wp_next_scheduled($hook) === false) {
            wp_schedule_event(time() + 30, self::SCHEDULE_PREFIX . $intervalSeconds, $hook);
        }
    }

    public function enqueueAsync(string $hook, array $args = []): void
    {
        if (wp_next_scheduled($hook, $args) === false) {
            wp_schedule_single_event(time(), $hook, $args);
        }
    }

    public function clear(string $hook): void
    {
        wp_clear_scheduled_hook($hook);
    }

    public function name(): string
    {
        return 'wp_cron';
    }
}
