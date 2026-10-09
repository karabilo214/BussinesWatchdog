<?php

namespace BusinessWatchdog\WooCommerce\Attempts;

use BusinessWatchdog\WooCommerce\Capture\OrderCapture;
use BusinessWatchdog\WooCommerce\Synthetic\SyntheticRequest;

final class AttemptHooks
{
    private const STORE_API_CHECKOUT_ROUTE = '#^/wc/store(?:/v\d+)?/checkout/?$#';

    private static $classicMethod = null;

    private static $errors = 0;

    private static $started = [];

    public static function register(): void
    {
        add_action('woocommerce_checkout_process', [self::class, 'onClassicCheckout'], 1, 0);
        add_filter('woocommerce_add_error', [self::class, 'onError'], 999, 1);
        add_action('woocommerce_checkout_order_processed', [self::class, 'onClassicOrderProcessed'], 999, 3);
        add_action('woocommerce_store_api_checkout_order_processed', [self::class, 'onBlocksOrderProcessed'], 999, 1);
        add_action('woocommerce_blocks_checkout_order_processed', [self::class, 'onBlocksOrderProcessed'], 999, 1);
        add_filter('rest_request_after_callbacks', [self::class, 'onRestResponse'], 999, 3);
        add_action('woocommerce_payment_complete', [self::class, 'onPaymentComplete'], 999, 1);
        add_action('woocommerce_order_status_changed', [self::class, 'onStatusChanged'], 999, 3);
        add_action('shutdown', [self::class, 'finishRequest'], 5, 0);
    }

    public static function onClassicCheckout(): void
    {
        if (! OrderCapture::capturing() || SyntheticRequest::current() !== null || ! empty($_POST['woocommerce_checkout_update_totals'])) {
            return;
        }

        self::$classicMethod = AttemptLog::method(wp_unslash($_POST['payment_method'] ?? ''));
    }

    public static function onError($message)
    {
        self::$errors++;

        return $message;
    }

    public static function onClassicOrderProcessed($orderId, $postedData = null, $order = null): void
    {
        self::start($order instanceof \WC_Order ? $order : wc_get_order($orderId));
    }

    public static function onBlocksOrderProcessed($order): void
    {
        self::start($order instanceof \WC_Order ? $order : null);
    }

    public static function onRestResponse($response, $handler = null, $request = null)
    {
        try {
            if (! $request instanceof \WP_REST_Request
                || SyntheticRequest::current() !== null
                || strtoupper($request->get_method()) !== 'POST'
                || ! preg_match(self::STORE_API_CHECKOUT_ROUTE, (string) $request->get_route())
                || ! OrderCapture::capturing()) {
                return $response;
            }

            $code = self::errorCode($response);

            if ($code === null) {
                return $response;
            }

            if (self::$started === []) {
                AttemptLog::reject((string) $request->get_param('payment_method'), 'validation');
            } else {
                foreach (array_keys(self::$started) as $orderId) {
                    AttemptLog::resolveOpen($orderId, AttemptLog::FAILED, strpos($code, 'payment') !== false ? 'payment_error' : 'checkout_error');
                }
            }

            self::$started = [];
        } catch (\Throwable $exception) {
        }

        return $response;
    }

    public static function onPaymentComplete($orderId): void
    {
        if (OrderCapture::capturing()) {
            AttemptLog::succeed((int) $orderId, AttemptLog::PAID);
        }
    }

    public static function onStatusChanged($orderId, $from = '', $to = ''): void
    {
        if (! OrderCapture::capturing()) {
            return;
        }

        $orderId = (int) $orderId;

        if (in_array($to, wc_get_is_paid_statuses(), true)) {
            AttemptLog::succeed($orderId, AttemptLog::PAID);
        } elseif ($to === 'on-hold') {
            AttemptLog::succeed($orderId, AttemptLog::ON_HOLD);
        } elseif ($to === 'failed') {
            AttemptLog::resolveOpen($orderId, AttemptLog::FAILED, 'status_failed');
        } elseif ($to === 'cancelled') {
            AttemptLog::resolveOpen($orderId, AttemptLog::FAILED, 'cancelled');
        }
    }

    public static function finishRequest(): void
    {
        try {
            if (self::$classicMethod !== null) {
                if (self::$started === []) {
                    if (self::$errors > 0) {
                        AttemptLog::reject(self::$classicMethod, 'validation');
                    }
                } else {
                    foreach (self::$started as $orderId => $errorsAtStart) {
                        if (self::$errors > $errorsAtStart) {
                            AttemptLog::resolveOpen($orderId, AttemptLog::FAILED, 'gateway_error');
                        }
                    }
                }
            }
        } catch (\Throwable $exception) {
        }

        self::reset();
    }

    public static function reset(): void
    {
        self::$classicMethod = null;
        self::$errors = 0;
        self::$started = [];
    }

    private static function start($order): void
    {
        if (! $order instanceof \WC_Order || $order instanceof \WC_Order_Refund || ! OrderCapture::capturing() || SyntheticRequest::current() !== null) {
            return;
        }

        $orderId = (int) $order->get_id();

        if ($orderId <= 0 || isset(self::$started[$orderId])) {
            return;
        }

        try {
            AttemptLog::start($orderId, (string) $order->get_payment_method());
            self::$started[$orderId] = self::$errors;
        } catch (\Throwable $exception) {
        }
    }

    private static function errorCode($response): ?string
    {
        if (is_wp_error($response)) {
            return (string) $response->get_error_code();
        }

        if ($response instanceof \WP_HTTP_Response) {
            $data = $response->get_data();

            if ($response->get_status() >= 400) {
                return is_array($data) && isset($data['code']) ? (string) $data['code'] : 'http_' . $response->get_status();
            }

            $status = is_array($data) ? ($data['payment_result']['payment_status'] ?? null) : null;

            if (in_array($status, ['failure', 'error'], true)) {
                return 'payment_' . $status;
            }
        }

        return null;
    }
}
