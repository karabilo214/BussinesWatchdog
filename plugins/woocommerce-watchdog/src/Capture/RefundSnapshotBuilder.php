<?php

namespace BusinessWatchdog\WooCommerce\Capture;

use BusinessWatchdog\WooCommerce\Money\CurrencyExponent;
use BusinessWatchdog\WooCommerce\Money\MinorUnits;

final class RefundSnapshotBuilder
{
    public static function build(\WC_Order_Refund $refund, \WC_Order $parent): array
    {
        $currency = strtoupper((string) ($refund->get_currency('edit') ?: $parent->get_currency('edit')));

        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new SnapshotUnavailable('currency_invalid');
        }

        $exponent = CurrencyExponent::for($currency);
        $amount = MinorUnits::fromDecimal(self::absoluteDecimal($refund->get_amount('edit')), $exponent);

        if ($amount === null) {
            throw new SnapshotUnavailable('amount_not_representable');
        }

        return [
            'order_id' => (string) $parent->get_id(),
            'currency' => $currency,
            'currency_exponent' => $exponent,
            'amount_minor' => $amount,
            'external_required' => method_exists($refund, 'get_refunded_payment') ? (bool) $refund->get_refunded_payment('edit') : null,
            'status' => 'recorded',
        ];
    }

    private static function absoluteDecimal($value)
    {
        if (is_string($value)) {
            return ltrim(trim($value), '-');
        }

        return is_float($value) || is_int($value) ? abs($value) : $value;
    }
}
