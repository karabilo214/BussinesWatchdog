<?php

namespace App\Console\Commands;

use App\Support\Billing\Paddle\PaddleEventProcessor;
use Illuminate\Console\Command;

class ProcessBillingEvents extends Command
{
    protected $signature = 'billing:process-events {--limit=100}';

    protected $description = 'Project due billing inbox events into subscriptions and checkout sessions.';

    public function handle(PaddleEventProcessor $processor): int
    {
        $summary = $processor->processDue(max(1, (int) $this->option('limit')));
        $this->components->info(sprintf('Billing events processed: %d, retried: %d, quarantined: %d', $summary['processed'], $summary['retried'], $summary['quarantined']));

        return self::SUCCESS;
    }
}
