<?php

namespace App\Support\Notifications;

use InvalidArgumentException;

final class MinorUnits
{
    public static function format(string $minor, int $exponent): string
    {
        if (preg_match('/^-?\d+$/', $minor) !== 1 || $exponent < 0) {
            throw new InvalidArgumentException('Invalid minor-unit amount.');
        }

        $negative = str_starts_with($minor, '-');
        $digits = ltrim($negative ? substr($minor, 1) : $minor, '0');
        $digits = str_pad($digits, $exponent + 1, '0', STR_PAD_LEFT);

        $formatted = $exponent === 0
            ? $digits
            : substr($digits, 0, -$exponent).'.'.substr($digits, -$exponent);

        return ($negative && trim($digits, '0') !== '' ? '-' : '').$formatted;
    }
}
