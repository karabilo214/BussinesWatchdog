<?php

namespace App\Console\Commands;

use App\Models\Integration;
use App\Support\Providers\WooCommerce\WooCommerceApiConnector;
use App\Support\Providers\WooCommerce\WooCommerceApiSync;
use Illuminate\Console\Command;

class SyncWooCommerceApiIntegrations extends Command
{
    protected $signature = 'woocommerce-api:sync {--recent : Re-read orders created in the last hours} {--audit : Re-read the retention window and look for deleted orders}';

    protected $description = 'Poll stores connected through a WooCommerce REST API key';

    public function handle(WooCommerceApiSync $sync): int
    {
        $mode = match (true) {
            (bool) $this->option('audit') => WooCommerceApiSync::MODE_AUDIT,
            (bool) $this->option('recent') => WooCommerceApiSync::MODE_RECENT,
            default => WooCommerceApiSync::MODE_DELTA,
        };
        $integrations = Integration::query()
            ->where('provider', WooCommerceApiConnector::PROVIDER)
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
