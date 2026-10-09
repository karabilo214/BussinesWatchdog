<?php

namespace BusinessWatchdog\WooCommerce\Compat;

final class ActionSchedulerAdapter implements Scheduler
{
    private const GROUP = 'business-watchdog';

    public function ensureRecurring(string $hook, int $intervalSeconds): void
    {
        if (as_next_scheduled_action($hook, [], self::GROUP) === false) {
            as_schedule_recurring_action(time() + 30, $intervalSeconds, $hook, [], self::GROUP);
        }
    }

    public function enqueueAsync(string $hook, array $args = []): void
    {
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action($hook, $args, self::GROUP);

            return;
        }

        as_schedule_single_action(time(), $hook, $args, self::GROUP);
    }

    public function clear(string $hook): void
    {
        as_unschedule_all_actions($hook, [], self::GROUP);
    }

    public function name(): string
    {
        return 'action_scheduler';
    }
}
