<?php

namespace App\Support\Providers\WooCommerce;

/**
 * Store amounts as the WooCommerce plugin sends them: ISO 4217 minor units (the same exponent table as the plugin's
 * `CurrencyExponent`), converted from the REST API's decimal strings without floats. A value with more precision
 * than the currency allows is not representable and is not sent.
 */
final class WooCommerceAmount
{
    private const ZERO = ['BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'UYI', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

    private const THREE = ['BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND'];

    public static function exponent(string $currency): int
    {
        $currency = strtoupper($currency);

        if (in_array($currency, self::ZERO, true)) {
            return 0;
        }

        return in_array($currency, self::THREE, true) ? 3 : 2;
    }

    /** "184.000000" with exponent 2 → "18400"; "-30.00" → "3000" when $absolute; null when not representable. */
    public static function toMinor(mixed $value, int $exponent, bool $absolute = false): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($absolute && str_starts_with($value, '-')) {
            $value = substr($value, 1);
        }

        if (preg_match('/^(\d{1,16})(?:\.(\d+))?$/', $value, $parts) !== 1) {
            return null;
        }

        $fraction = $parts[2] ?? '';

        if (strlen(rtrim($fraction, '0')) > $exponent) {
            return null;
        }

        $digits = ltrim($parts[1].str_pad(substr($fraction, 0, $exponent), $exponent, '0'), '0');

        return $digits === '' ? '0' : $digits;
    }
}
