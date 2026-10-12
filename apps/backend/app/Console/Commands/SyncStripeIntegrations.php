<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\QueuesIntegrationSyncs;
use App\Support\Integrations\IntegrationSyncRunner;
use App\Support\Providers\Stripe\StripeSync;
use Illuminate\Console\Command;

class SyncStripeIntegrations extends Command
{
    use QueuesIntegrationSyncs;

    protected $signature = 'stripe:sync {--audit : Re-read the whole retention window instead of the delta} {--inline : Sync one integration after another in this process instead of queueing them}';

    protected $description = 'Poll active Stripe integrations for payment intents, charges and refunds';

    public function handle(IntegrationSyncRunner $runner): int
    {
        return $this->queueSyncs($runner, 'stripe', $this->option('audit') ? StripeSync::MODE_AUDIT : StripeSync::MODE_DELTA);
    }
}
