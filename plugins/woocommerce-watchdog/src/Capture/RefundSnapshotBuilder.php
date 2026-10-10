<?php

namespace BusinessWatchdog\WooCommerce\Capture;

use BusinessWatchdog\WooCommerce\Money\CurrencyExponent;
use BusinessWatchdog\WooCommerce\Money\MinorUnits;

final class RefundSnapshotBuilder
{
    /**
     * Refund meta where a payment gateway stores the provider's refund id. Only keys confirmed against a real
     * gateway are listed: WooCommerce Stripe Gateway 11.0.1 writes `_stripe_refund_id` (ADR 0020, spike part 3).
     */
    private const PROVIDER_REFUND_ID_META = ['_stripe_refund_id'];

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
            'provider_ref' => self::providerRef($refund),
            'status' => 'recorded',
        ];
    }

    private static function providerRef(\WC_Order_Refund $refund)
    {
        foreach (self::PROVIDER_REFUND_ID_META as $key) {
            $value = trim((string) $refund->get_meta($key, true, 'edit'));

            if ($value !== '') {
                return substr($value, 0, 255);
            }
        }

        return null;
    }

    private static function absoluteDecimal($value)
    {
        if (is_string($value)) {
            return ltrim(trim($value), '-');
        }

        return is_float($value) || is_int($value) ? abs($value) : $value;
    }
}
