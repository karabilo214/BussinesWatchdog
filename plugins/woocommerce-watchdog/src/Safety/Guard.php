<?php

namespace BusinessWatchdog\WooCommerce\Safety;

/** Keep monitoring failures out of WordPress and WooCommerce request handling. */
final class Guard
{
    public static function action($callback, string $name): callable
    {
        return static function (...$args) use ($callback, $name): void {
            try {
                if (is_callable($callback)) {
                    $callback(...$args);
                } else {
                    self::failed($name);
                }
            } catch (\Throwable $exception) {
                self::failed($name);
            }
        };
    }

    public static function filter($callback, string $name): callable
    {
        return static function (...$args) use ($callback, $name) {
            try {
                return is_callable($callback) ? $callback(...$args) : ($args[0] ?? null);
            } catch (\Throwable $exception) {
                self::failed($name);

                return $args[0] ?? null;
            }
        };
    }

    private static function failed(string $name): void
    {
        // A fixed code only: no order data, request body, exception text or secret is logged.
        error_log('business-watchdog: callback_failed ' . $name);
    }
}
