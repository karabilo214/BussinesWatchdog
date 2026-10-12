<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\QueuesIntegrationSyncs;
use App\Support\Integrations\IntegrationSyncRunner;
use App\Support\Providers\PayPal\PayPalSync;
use Illuminate\Console\Command;

class SyncPayPalIntegrations extends Command
{
    use QueuesIntegrationSyncs;

    protected $signature = 'paypal:sync {--audit : Walk the whole retention window instead of the delta} {--pointers : Read the captures that recent store orders point to} {--inline : Sync one integration after another in this process instead of queueing them}';

    protected $description = 'Poll active PayPal integrations through Transaction Search and read the affected orders';

    public function handle(IntegrationSyncRunner $runner): int
    {
        return $this->queueSyncs($runner, 'paypal', match (true) {
            (bool) $this->option('pointers') => PayPalSync::MODE_POINTERS,
            (bool) $this->option('audit') => PayPalSync::MODE_AUDIT,
            default => PayPalSync::MODE_DELTA,
        });
    }
}
