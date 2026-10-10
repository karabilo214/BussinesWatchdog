<?php

namespace App\Console\Commands;

use App\Models\Integration;
use App\Support\Providers\PayPal\PayPalSync;
use Illuminate\Console\Command;

class SyncPayPalIntegrations extends Command
{
    protected $signature = 'paypal:sync {--audit : Walk the whole retention window instead of the delta}';

    protected $description = 'Poll active PayPal integrations through Transaction Search and read the affected orders';

    public function handle(PayPalSync $sync): int
    {
        $mode = $this->option('audit') ? PayPalSync::MODE_AUDIT : PayPalSync::MODE_DELTA;
        $integrations = Integration::query()
            ->where('provider', 'paypal')
            ->where('status', Integration::STATUS_ACTIVE)
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
