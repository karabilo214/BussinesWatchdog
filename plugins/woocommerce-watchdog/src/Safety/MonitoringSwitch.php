<?php

namespace BusinessWatchdog\WooCommerce\Safety;

use BusinessWatchdog\WooCommerce\Plugin;

final class MonitoringSwitch
{
    public const OPTION = 'business_watchdog_monitoring_stopped';

    public static function stopped(): bool
    {
        return is_array(get_option(self::OPTION, null));
    }

    public static function stoppedSince(): ?string
    {
        $value = get_option(self::OPTION, null);

        return is_array($value) && is_string($value['at'] ?? null) ? $value['at'] : null;
    }

    public static function stop(string $source): void
    {
        update_option(self::OPTION, ['at' => gmdate('c'), 'source' => $source], true);
        Plugin::clearSchedules();
    }

    public static function resume(): void
    {
        delete_option(self::OPTION);
        Plugin::ensureSchedules();
    }
}
