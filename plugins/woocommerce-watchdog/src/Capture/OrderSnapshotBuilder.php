<?php

namespace BusinessWatchdog\WooCommerce\Capture;

use BusinessWatchdog\WooCommerce\Money\CurrencyExponent;
use BusinessWatchdog\WooCommerce\Money\MinorUnits;

final class OrderSnapshotBuilder
{
    public const SKIPPED_STATUSES = ['checkout-draft', 'auto-draft'];

    public static function supportedGateway(string $gateway): bool
    {
        return $gateway === 'stripe' || strpos($gateway, 'stripe_') === 0;
    }

    public static function build(\WC_Order $order): array
    {
        $currency = strtoupper((string) $order->get_currency('edit'));

        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new SnapshotUnavailable('currency_invalid');
        }

        $exponent = CurrencyExponent::for($currency);
        $total = MinorUnits::fromDecimal($order->get_total('edit'), $exponent);

        if ($total === null) {
            throw new SnapshotUnavailable('amount_not_representable');
        }

        $gateway = (string) $order->get_payment_method('edit');
        $transactionRef = (string) $order->get_transaction_id('edit');
        $created = EventFactory::isoDate($order->get_date_created('edit'));
        $modified = EventFactory::isoDate($order->get_date_modified('edit'));
        $displayNumber = (string) $order->get_order_number();

        $data = [
            'status' => (string) $order->get_status('edit'),
            'currency' => $currency,
            'currency_exponent' => $exponent,
            'total_minor' => $total,
            'gateway' => $gateway !== '' ? substr($gateway, 0, 255) : null,
            'transaction_ref' => $transactionRef !== '' ? substr($transactionRef, 0, 255) : null,
            'payment_expected' => $total !== '0',
            'paid_marked_at' => EventFactory::isoDate($order->get_date_paid('edit')),
            'financial_support' => $gateway === '' ? 'unknown' : (self::supportedGateway($gateway) ? 'supported' : 'unsupported'),
        ];

        if ($displayNumber !== '') {
            $data['display_number'] = substr($displayNumber, 0, 255);
        }

        if ($created !== null) {
            $data['source_created_at'] = $created;
        }

        if ($modified !== null || $created !== null) {
            $data['source_updated_at'] = $modified ?? $created;
        }

        return $data;
    }
}
