<?php

namespace BusinessWatchdog\WooCommerce\Http;

use BusinessWatchdog\WooCommerce\Connection\Connection;

final class SignedClient
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function post(string $path, array $payload): Response
    {
        $secret = $this->connection->secretBytes();

        if ($secret === null) {
            return new Response(0, null, 'secret_unreadable', null);
        }

        $body = (string) wp_json_encode($payload === [] ? (object) [] : $payload);
        $timestamp = (string) time();
        $nonce = wp_generate_uuid4();
        $canonical = implode("\n", ['v1', $timestamp, $nonce, 'POST', $path, hash('sha256', $body)]);

        return Transport::postJson($this->connection->endpoint() . $path, $body, [
            'X-BW-Key-Id' => $this->connection->keyId(),
            'X-BW-Timestamp' => $timestamp,
            'X-BW-Nonce' => $nonce,
            'X-BW-Signature' => hash_hmac('sha256', $canonical, $secret),
            'X-BW-Signature-Version' => '1',
        ]);
    }
}
