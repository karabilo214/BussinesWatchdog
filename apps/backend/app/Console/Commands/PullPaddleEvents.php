<?php

namespace App\Console\Commands;

use App\Support\Billing\Paddle\PaddleConfig;
use App\Support\Billing\Paddle\PaddleEventPuller;
use App\Support\Billing\Paddle\PaddleRequestFailed;
use Illuminate\Console\Command;

class PullPaddleEvents extends Command
{
    protected $signature = 'billing:paddle-events';

    protected $description = 'Read new events from the Paddle event log into the billing inbox.';

    public function handle(PaddleEventPuller $puller): int
    {
        if (! PaddleConfig::configured()) {
            return self::SUCCESS;
        }

        try {
            $summary = $puller->pull();
        } catch (PaddleRequestFailed $exception) {
            $this->components->error('Paddle: '.$exception->reasonCode);

            return self::FAILURE;
        }

        $this->components->info(sprintf('Paddle events read: %d, new: %d', $summary['read'], $summary['stored']));

        return self::SUCCESS;
    }
}
