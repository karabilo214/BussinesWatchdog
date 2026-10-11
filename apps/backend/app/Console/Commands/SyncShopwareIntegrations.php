<?php

namespace App\Console\Commands;

use App\Models\Integration;
use App\Support\Providers\Shopware\ShopwareConnector;
use App\Support\Providers\Shopware\ShopwareSync;
use Illuminate\Console\Command;

class SyncShopwareIntegrations extends Command
{
    protected $signature = 'shopware:sync {--audit : Re-read the retention window and look for deleted orders}';

    protected $description = 'Poll Shopware stores connected through an Integration key';

    public function handle(ShopwareSync $sync): int
    {
        $mode = $this->option('audit') ? ShopwareSync::MODE_AUDIT : ShopwareSync::MODE_DELTA;
        $integrations = Integration::query()
            ->where('provider', ShopwareConnector::PROVIDER)
            ->whereIn('status', [Integration::STATUS_ACTIVE, Integration::STATUS_DEGRADED])
            ->orderByRaw('last_successful_sync_at IS NOT NULL')
            ->orderBy('last_successful_sync_at')
            ->get();

        foreach ($integrations as $integration) {
            $result = $sync->run($integration, $mode);
            $this->line(sprintf('%s %s emitted=%d unchanged=%d complete=%s%s', $integration->id, $result['status'], $result['emitted'], $result['unchanged'], $result['complete'] ? 'yes' : 'no', isset($result['error']) ? ' error='.$result['error'] : ''));
        }

        return self::SUCCESS;
    }
}
