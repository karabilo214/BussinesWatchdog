<?php

namespace BusinessWatchdog\WooCommerce\Compat;

final class SchedulerFactory
{
    public static function make(): Scheduler
    {
        return Environment::actionSchedulerAvailable() ? new ActionSchedulerAdapter() : new WpCronAdapter();
    }
}
