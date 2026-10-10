<?php

namespace App\Console\Commands;

use App\Models\Integration;
use App\Support\Providers\Stripe\StripeSync;
use Illuminate\Console\Command;

class SyncStripeIntegrations extends Command
{
    protected $signature = 'stripe:sync {--audit : Re-read the whole retention window instead of the delta}';

    protected $description = 'Poll active Stripe integrations for payment intents, charges and refunds';

    public function handle(StripeSync $sync): int
    {
        $mode = $this->option('audit') ? StripeSync::MODE_AUDIT : StripeSync::MODE_DELTA;
        $integrations = Integration::query()
            ->where('provider', 'stripe')
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
