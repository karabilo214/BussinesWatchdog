<?php

namespace BusinessWatchdog\WooCommerce\Cli;

use BusinessWatchdog\WooCommerce\Connection\PairingClient;
use BusinessWatchdog\WooCommerce\Jobs\HeartbeatJob;
use BusinessWatchdog\WooCommerce\Rest\RestController;

final class Command
{
    public function pair(array $args, array $assoc): void
    {
        $result = PairingClient::pair((string) $assoc['endpoint'], (string) $assoc['code']);

        if (! $result['ok']) {
            \WP_CLI::error('Pairing failed: ' . $result['code']);
        }

        \WP_CLI::success('Paired.');
    }

    public function heartbeat(): void
    {
        \WP_CLI::line((string) wp_json_encode(HeartbeatJob::run()));
    }

    public function diagnostics(): void
    {
        \WP_CLI::line((string) wp_json_encode(RestController::diagnosticsPayload()));
    }
}
