<?php

namespace App\Support\Providers\PayPal;

/**
 * PayPal REST amounts are decimal strings. Currencies and their decimals follow PayPal's currency-codes reference:
 * HUF, JPY and TWD take no decimals. Other currencies are not mapped (ADR 0022). Conversion is string-only.
 */
final class PayPalCurrency
{
    private const SUPPORTED = ['AUD', 'BRL', 'CAD', 'CHF', 'CNY', 'CZK', 'DKK', 'EUR', 'GBP', 'HKD', 'HUF', 'ILS', 'JPY', 'MXN', 'MYR', 'NOK', 'NZD', 'PHP', 'PLN', 'SEK', 'SGD', 'THB', 'TWD', 'USD'];

    private const ZERO_DECIMAL = ['HUF', 'JPY', 'TWD'];

    public static function exponent(string $currency): ?int
    {
        $code = strtoupper($currency);

        if (! in_array($code, self::SUPPORTED, true)) {
            return null;
        }

        return in_array($code, self::ZERO_DECIMAL, true) ? 0 : 2;
    }

    /** "10.5" with exponent 2 → "1050"; null for negative, malformed or over-precise values. */
    public static function toMinor(mixed $value, int $exponent): ?string
    {
        if (! is_string($value) || preg_match('/^(\d{1,15})(?:\.(\d+))?$/', $value, $parts) !== 1) {
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
