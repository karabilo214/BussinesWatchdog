<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\QueuesIntegrationSyncs;
use App\Support\Integrations\IntegrationSyncRunner;
use App\Support\Providers\WooCommerce\WooCommerceApiConnector;
use App\Support\Providers\WooCommerce\WooCommerceApiSync;
use Illuminate\Console\Command;

class SyncWooCommerceApiIntegrations extends Command
{
    use QueuesIntegrationSyncs;

    protected $signature = 'woocommerce-api:sync {--recent : Re-read orders created in the last hours} {--audit : Re-read the retention window and look for deleted orders} {--inline : Sync one integration after another in this process instead of queueing them}';

    protected $description = 'Poll stores connected through a WooCommerce REST API key';

    public function handle(IntegrationSyncRunner $runner): int
    {
        return $this->queueSyncs($runner, WooCommerceApiConnector::PROVIDER, match (true) {
            (bool) $this->option('audit') => WooCommerceApiSync::MODE_AUDIT,
            (bool) $this->option('recent') => WooCommerceApiSync::MODE_RECENT,
            default => WooCommerceApiSync::MODE_DELTA,
        });
    }
}
