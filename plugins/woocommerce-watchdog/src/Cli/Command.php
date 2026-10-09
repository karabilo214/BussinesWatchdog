<?php

namespace BusinessWatchdog\WooCommerce\Cli;

use BusinessWatchdog\WooCommerce\Connection\PairingClient;
use BusinessWatchdog\WooCommerce\Jobs\BackfillJob;
use BusinessWatchdog\WooCommerce\Jobs\DeliveryJob;
use BusinessWatchdog\WooCommerce\Jobs\HeartbeatJob;
use BusinessWatchdog\WooCommerce\Jobs\RescanJob;
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

    public function deliver(): void
    {
        \WP_CLI::line((string) wp_json_encode(DeliveryJob::run()));
    }

    public function rescan(): void
    {
        \WP_CLI::line((string) wp_json_encode(RescanJob::run()));
    }

    public function backfill(array $args, array $assoc): void
    {
        if (isset($assoc['start'])) {
            BackfillJob::start('manual');
        }

        $state = BackfillJob::run(isset($assoc['pages']) ? max(1, (int) $assoc['pages']) : BackfillJob::PAGES_PER_RUN);
        \WP_CLI::line((string) wp_json_encode($state));
    }
}
