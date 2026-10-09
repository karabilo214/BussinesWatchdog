<?php

namespace BusinessWatchdog\WooCommerce\Money;

final class CurrencyExponent
{
    private const ZERO = ['BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'UYI', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

    private const THREE = ['BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND'];

    public static function for(string $currency): int
    {
        $currency = strtoupper($currency);

        if (in_array($currency, self::ZERO, true)) {
            return 0;
        }

        return in_array($currency, self::THREE, true) ? 3 : 2;
    }
}
