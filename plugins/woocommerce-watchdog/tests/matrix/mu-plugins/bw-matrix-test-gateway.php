<?php
/**
 * Plugin Name: Business Watchdog matrix test helpers
 */

if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
    $_SERVER['HTTPS'] = 'on';
}

add_action('plugins_loaded', static function () {
    if (! class_exists('WC_Payment_Gateway')) {
        return;
    }

    if (! class_exists('BW_Matrix_Decline_Gateway')) {
        class BW_Matrix_Decline_Gateway extends WC_Payment_Gateway
        {
            public function __construct()
            {
                $this->id = 'bw_test_decline';
                $this->method_title = 'Matrix decline';
                $this->title = 'Matrix decline';
                $this->enabled = 'yes';
                $this->has_fields = false;
                $this->supports = ['products'];
            }

            public function process_payment($order_id)
            {
                wc_add_notice('Matrix test decline', 'error');

                return ['result' => 'failure'];
            }
        }
    }

    if (get_option('bw_matrix_decline_gateway') === 'no') {
        return;
    }

    add_filter('woocommerce_payment_gateways', static function ($gateways) {
        $gateways[] = 'BW_Matrix_Decline_Gateway';

        return $gateways;
    });
}, 11);
