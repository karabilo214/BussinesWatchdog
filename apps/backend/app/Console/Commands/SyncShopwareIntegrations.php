<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\QueuesIntegrationSyncs;
use App\Support\Integrations\IntegrationSyncRunner;
use App\Support\Providers\Shopware\ShopwareConnector;
use App\Support\Providers\Shopware\ShopwareSync;
use Illuminate\Console\Command;

class SyncShopwareIntegrations extends Command
{
    use QueuesIntegrationSyncs;

    protected $signature = 'shopware:sync {--audit : Re-read the retention window and look for deleted orders} {--inline : Sync one integration after another in this process instead of queueing them}';

    protected $description = 'Poll Shopware stores connected through an Integration key';

    public function handle(IntegrationSyncRunner $runner): int
    {
        return $this->queueSyncs($runner, ShopwareConnector::PROVIDER, $this->option('audit') ? ShopwareSync::MODE_AUDIT : ShopwareSync::MODE_DELTA);
    }
}
