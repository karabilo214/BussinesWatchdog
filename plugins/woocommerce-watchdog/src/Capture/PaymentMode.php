<?php

namespace BusinessWatchdog\WooCommerce\Capture;

/**
 * Whether a payment of an order happened in the provider's test or live mode. For Stripe it is recorded once, when the
 * order is paid or authorized, because the gateway setting can be switched later and the daily re-scan must not
 * relabel old orders. PayPal Payments stores its own fixed `_ppcp_paypal_payment_mode` (sandbox/live) on the order,
 * which is read as is. Other gateways are not labelled (the backend keeps its default).
 */
final class PaymentMode
{
    public const META = '_bw_payment_mode';

    private const PAYPAL_META = '_ppcp_paypal_payment_mode';

    private const RECORD_ON_STATUSES = ['processing', 'completed', 'on-hold'];

    public static function register(): void
    {
        add_action('woocommerce_payment_complete', [self::class, 'onPaymentComplete'], 5, 1);
        add_action('woocommerce_order_status_changed', [self::class, 'onStatusChanged'], 5, 3);
    }

    public static function onPaymentComplete($orderId): void
    {
        self::recordFor($orderId);
    }

    public static function onStatusChanged($orderId, $from = '', $to = ''): void
    {
        if (in_array((string) $to, self::RECORD_ON_STATUSES, true)) {
            self::recordFor($orderId);
        }
    }

    public static function forOrder(\WC_Order $order)
    {
        $mode = (string) $order->get_meta(self::META, true, 'edit');

        if (in_array($mode, ['live', 'test'], true)) {
            return $mode;
        }

        if (strpos((string) $order->get_payment_method('edit'), 'ppcp-') === 0) {
            $paypal = (string) $order->get_meta(self::PAYPAL_META, true, 'edit');

            return $paypal === 'sandbox' ? 'test' : ($paypal === 'live' ? 'live' : null);
        }

        return null;
    }

    private static function recordFor($orderId): void
    {
        $order = function_exists('wc_get_order') ? wc_get_order($orderId) : null;

        if (! $order instanceof \WC_Order || self::forOrder($order) !== null) {
            return;
        }

        $gateway = (string) $order->get_payment_method('edit');

        if (! OrderSnapshotBuilder::supportedGateway($gateway) || strpos($gateway, 'ppcp-') === 0) {
            return;
        }

        $settings = get_option('woocommerce_stripe_settings', []);
        $mode = is_array($settings) && ($settings['testmode'] ?? 'no') === 'yes' ? 'test' : 'live';
        $order->update_meta_data(self::META, $mode);
        $order->save_meta_data();
    }
}
