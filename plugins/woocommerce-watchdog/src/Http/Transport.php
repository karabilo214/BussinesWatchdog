<?php

namespace BusinessWatchdog\WooCommerce\Http;

final class Transport
{
    public const TIMEOUT_SECONDS = 15;

    public static function endpointAllowed(string $endpoint): bool
    {
        $parts = wp_parse_url($endpoint);

        if (! is_array($parts) || empty($parts['host'])) {
            return false;
        }

        if (($parts['scheme'] ?? '') === 'https') {
            return true;
        }

        return ($parts['scheme'] ?? '') === 'http' && defined('BW_ALLOW_INSECURE_ENDPOINT') && BW_ALLOW_INSECURE_ENDPOINT;
    }

    public static function postJson(string $url, string $body, array $headers = []): Response
    {
        $result = wp_remote_post($url, [
            'timeout' => self::TIMEOUT_SECONDS,
            'redirection' => 0,
            'headers' => array_merge([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'User-Agent' => 'BusinessWatchdog-WooCommerce/' . BW_PLUGIN_VERSION,
            ], $headers),
            'body' => $body,
            'data_format' => 'body',
        ]);

        if (is_wp_error($result)) {
            return new Response(0, null, 'transport_error', null);
        }

        $status = (int) wp_remote_retrieve_response_code($result);
        $decoded = json_decode((string) wp_remote_retrieve_body($result), true);
        $json = is_array($decoded) ? $decoded : null;
        $retryAfter = wp_remote_retrieve_header($result, 'retry-after');

        return new Response(
            $status,
            $json,
            is_array($json) && isset($json['code']) ? (string) $json['code'] : null,
            is_numeric($retryAfter) ? (int) $retryAfter : null
        );
    }
}
