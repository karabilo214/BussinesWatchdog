<?php

namespace BusinessWatchdog\WooCommerce\Money;

final class MinorUnits
{
    public const MAX = '9223372036854775807';

    public static function fromDecimal($value, int $exponent): ?string
    {
        if (is_int($value)) {
            $value = (string) $value;
        } elseif (is_float($value)) {
            if (! is_finite($value)) {
                return null;
            }

            $value = sprintf('%.' . ($exponent + 6) . 'F', $value);
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if (preg_match('/^(\d*)(?:\.(\d*))?$/', $value, $matches) !== 1 || ($matches[1] === '' && ($matches[2] ?? '') === '')) {
            return null;
        }

        $whole = $matches[1] === '' ? '0' : $matches[1];
        $fraction = $matches[2] ?? '';
        $kept = substr(str_pad($fraction, $exponent, '0'), 0, $exponent);
        $dropped = (string) substr($fraction, $exponent);

        if (trim($dropped, '0') !== '') {
            return null;
        }

        $minor = ltrim($whole . $kept, '0');
        $minor = $minor === '' ? '0' : $minor;

        if (strlen($minor) > strlen(self::MAX) || (strlen($minor) === strlen(self::MAX) && strcmp($minor, self::MAX) > 0)) {
            return null;
        }

        return $minor;
    }
}
