<?php

namespace App\Support\Providers\Stripe;

/**
 * Exponent of Stripe API amounts per currency (Stripe docs, "Zero-decimal" and "Three-decimal currencies").
 * Special cases where the API amount does not follow ISO 4217 (ISK, HUF, TWD, UGX) are listed separately and
 * are not mapped until the compatibility spike confirms them (ADR 0020).
 */
final class StripeCurrency
{
    private const ZERO_DECIMAL = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

    private const THREE_DECIMAL = ['BHD', 'JOD', 'KWD', 'OMR', 'TND'];

    private const UNCONFIRMED = ['HUF', 'ISK', 'TWD', 'UGX'];

    public static function exponent(string $currency): ?int
    {
        $code = strtoupper($currency);

        if (preg_match('/^[A-Z]{3}$/', $code) !== 1 || in_array($code, self::UNCONFIRMED, true)) {
            return null;
        }

        return match (true) {
            in_array($code, self::ZERO_DECIMAL, true) => 0,
            in_array($code, self::THREE_DECIMAL, true) => 3,
            default => 2,
        };
    }
}
