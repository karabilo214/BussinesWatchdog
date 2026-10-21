<?php
/**
 * Matrix only: lets WooCommerce deliver webhooks to the e2e service on the Docker host (a private address on a port
 * wp_safe_remote_request refuses, resolved over IPv4) while the option bw_e2e_allow_local_webhooks is set by
 * rest-api-webhook-e2e.sh.
 */
if (get_option('bw_e2e_allow_local_webhooks') === '1') {
    add_filter('http_request_host_is_external', static function ($external, $host) {
        return $host === 'host.docker.internal' ? true : $external;
    }, 10, 2);
    add_filter('http_allowed_safe_ports', static function ($ports) {
        $ports[] = 8777;

        return $ports;
    });
    add_action('http_api_curl', static function ($handle, $args, $url) {
        if (parse_url($url, PHP_URL_HOST) === 'host.docker.internal') {
            curl_setopt($handle, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        }
    }, 10, 3);
    add_filter('woocommerce_webhook_deliver_async', '__return_false');
}
