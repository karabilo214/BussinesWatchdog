<?php

namespace BusinessWatchdog\WooCommerce\Connection;

use BusinessWatchdog\WooCommerce\Http\Transport;
use BusinessWatchdog\WooCommerce\Storage\State;

final class PairingClient
{
    public const CONNECTOR_CODE = 'woocommerce';

    public static function siteBaseUrl(): string
    {
        return untrailingslashit(home_url());
    }

    /**
     * @return array{ok: bool, code: ?string}
     */
    public static function pair(string $endpoint, string $pairingCode): array
    {
        $endpoint = untrailingslashit(trim($endpoint));

        if (! Transport::endpointAllowed($endpoint)) {
            return ['ok' => false, 'code' => 'endpoint_not_https'];
        }

        $response = Transport::postJson($endpoint . '/api/v1/pairing/exchange', (string) wp_json_encode([
            'pairing_code' => trim($pairingCode),
            'install_id' => State::installId(),
            'connector_code' => self::CONNECTOR_CODE,
            'plugin_version' => BW_PLUGIN_VERSION,
            'base_url' => self::siteBaseUrl(),
        ]));

        if ($response->status !== 201 || ! is_array($response->json)) {
            return ['ok' => false, 'code' => $response->errorCode ?? ($response->transportFailed() ? 'transport_error' : 'pairing_failed')];
        }

        $json = $response->json;

        if (! isset($json['integration_id'], $json['key_id'], $json['secret']) || ($json['secret_encoding'] ?? null) !== 'base64') {
            return ['ok' => false, 'code' => 'pairing_response_invalid'];
        }

        Connection::store($endpoint, (string) $json['integration_id'], (string) $json['key_id'], (string) $json['secret']);

        return ['ok' => true, 'code' => null];
    }
}
