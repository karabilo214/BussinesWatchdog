<?php
/**
 * Plugin Name: Business Watchdog purchase fault lab
 * Description: Local matrix-only checkout faults. Requires an explicit request header.
 */

function bw_purchase_fault_mode(): string
{
    if (function_exists('wp_get_environment_type') && wp_get_environment_type() !== 'local') {
        return '';
    }

    $mode = (string) ($_SERVER['HTTP_X_BW_FAULT_MODE'] ?? '');
    $allowed = ['product_button', 'cart_button', 'cart_script', 'cart_price', 'cannot_buy', 'gateway_no_response'];

    return in_array($mode, $allowed, true) ? $mode : '';
}

add_action('wp_footer', static function (): void {
    $mode = bw_purchase_fault_mode();
    if (! in_array($mode, ['product_button', 'cart_button', 'cart_script'], true)) {
        return;
    }

    $script = [
        'product_button' => "document.querySelectorAll('form.cart .single_add_to_cart_button').forEach(b => { b.disabled = true; b.setAttribute('aria-disabled', 'true'); });",
        'cart_button' => "document.querySelectorAll('a.checkout-button').forEach(b => { b.style.pointerEvents = 'none'; b.setAttribute('aria-disabled', 'true'); });",
        'cart_script' => "document.querySelectorAll('a.checkout-button').forEach(b => b.addEventListener('click', e => { e.preventDefault(); e.stopImmediatePropagation(); }, true));",
    ][$mode];
    echo '<script data-bw-fault="' . esc_attr($mode) . '">' . $script . '</script>';
}, 1000);

add_action('woocommerce_before_calculate_totals', static function ($cart): void {
    if (bw_purchase_fault_mode() !== 'cart_price' || ! is_object($cart)) {
        return;
    }
    foreach ($cart->get_cart() as $item) {
        if (isset($item['data']) && is_object($item['data'])) {
            $item['data']->set_price('12.00');
        }
    }
}, 1000);

add_filter('woocommerce_available_payment_gateways', static function ($gateways) {
    return bw_purchase_fault_mode() === 'cannot_buy' ? [] : $gateways;
}, 1000);

add_action('plugins_loaded', static function (): void {
    if (! class_exists('WC_Payment_Gateway')) {
        return;
    }

    if (! class_exists('BW_Matrix_No_Response_Gateway')) {
        class BW_Matrix_No_Response_Gateway extends WC_Payment_Gateway
        {
            public function __construct()
            {
                $this->id = 'bw_test_no_response';
                $this->method_title = 'Matrix gateway no response';
                $this->title = 'Matrix gateway no response';
                $this->enabled = 'yes';
                $this->has_fields = false;
                $this->supports = ['products'];
            }

            public function process_payment($order_id)
            {
                wp_die('Simulated gateway did not answer', 'Gateway timeout', ['response' => 504]);
            }
        }
    }

    add_filter('woocommerce_payment_gateways', static function ($gateways) {
        if (bw_purchase_fault_mode() === 'gateway_no_response') {
            $gateways[] = 'BW_Matrix_No_Response_Gateway';
        }

        return $gateways;
    });
}, 11);
