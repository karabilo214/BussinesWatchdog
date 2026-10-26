<?php

namespace BusinessWatchdog\WooCommerce\Observation;

/**
 * Whether the service wants this store's checkout observed (ADR 0059), from the heartbeat answer. Kept in an autoloaded
 * option so storefront pages need no extra query.
 */
final class ObservationSettings
{
    public const OPTION = 'business_watchdog_checkout_observation';

    public static function enabled(): bool
    {
        $value = get_option(self::OPTION, null);

        return is_array($value) && ($value['observe'] ?? false) === true;
    }

    public static function remember($answer): void
    {
        $observe = is_array($answer) && ($answer['observe'] ?? false) === true;
        $current = get_option(self::OPTION, null);

        if (! is_array($current) || ($current['observe'] ?? null) !== $observe) {
            update_option(self::OPTION, ['observe' => $observe, 'since' => gmdate('c')], true);
        }
    }

    public static function forget(): void
    {
        delete_option(self::OPTION);
    }

    public static function describe(): array
    {
        $value = get_option(self::OPTION, null);

        return [
            'observe' => is_array($value) && ($value['observe'] ?? false) === true,
            'since' => is_array($value) ? ($value['since'] ?? null) : null,
            'script_altered_at' => get_option(CheckoutObservation::ALTERED_OPTION, null),
        ];
    }
}
