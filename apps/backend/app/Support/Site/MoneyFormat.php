<?php

namespace App\Support\Site;

use NumberFormatter;

/**
 * Formats a minor-unit amount for a locale without turning it into a float: the integer part goes through the locale's
 * grouping, the fraction is kept as digits, and the result is put into the locale's currency pattern.
 */
final class MoneyFormat
{
    public static function format(string $minor, int $exponent, string $currency, string $locale): string
    {
        if (preg_match('/^-?\d+$/', $minor) !== 1 || $exponent < 0 || $exponent > 6 || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            return $minor.' '.$currency;
        }

        $negative = str_starts_with($minor, '-');
        $digits = str_pad(ltrim($negative ? substr($minor, 1) : $minor, '0'), $exponent + 1, '0', STR_PAD_LEFT);
        $whole = $exponent === 0 ? $digits : substr($digits, 0, -$exponent);
        $fraction = $exponent === 0 ? '' : substr($digits, -$exponent);

        $currencyFormatter = new NumberFormatter($locale, NumberFormatter::CURRENCY);
        $currencyFormatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $exponent);
        $currencyFormatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $exponent);
        $template = $currencyFormatter->formatCurrency(0, $currency);
        $decimal = $currencyFormatter->getSymbol(NumberFormatter::MONETARY_SEPARATOR_SYMBOL);
        $zero = '0'.($exponent === 0 ? '' : $decimal.str_repeat('0', $exponent));

        $grouping = new NumberFormatter($locale, NumberFormatter::DECIMAL);
        $grouping->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, 0);
        $groupedWhole = strlen($whole) <= 15 ? (string) $grouping->format((int) $whole) : $whole;
        $number = $groupedWhole.($fraction === '' ? '' : $decimal.$fraction);

        if ($template === false || ! str_contains($template, $zero)) {
            return ($negative ? '-' : '').$number.' '.$currency;
        }

        return ($negative ? '-' : '').str_replace($zero, $number, $template);
    }
}
