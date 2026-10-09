<?php

namespace BusinessWatchdog\WooCommerce;

final class Autoloader
{
    private const PREFIX = 'BusinessWatchdog\\WooCommerce\\';

    public static function register(string $baseDir): void
    {
        spl_autoload_register(static function (string $class) use ($baseDir): void {
            if (strpos($class, self::PREFIX) !== 0) {
                return;
            }

            $relative = substr($class, strlen(self::PREFIX));
            $file = $baseDir . '/' . str_replace('\\', '/', $relative) . '.php';

            if (is_readable($file)) {
                require_once $file;
            }
        });
    }
}
