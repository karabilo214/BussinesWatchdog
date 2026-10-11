<?php

namespace App\Console\Commands;

use App\Support\Billing\Paddle\PaddleCatalogSync;
use App\Support\Billing\Paddle\PaddleConfig;
use App\Support\Billing\Paddle\PaddleRequestFailed;
use Illuminate\Console\Command;

class SyncPaddleCatalog extends Command
{
    protected $signature = 'billing:paddle-catalog';

    protected $description = 'Create Paddle products and prices for local Paddle prices of the current mode that have none yet.';

    public function handle(PaddleCatalogSync $catalog): int
    {
        if (! PaddleConfig::configured()) {
            $this->components->warn('Paddle is not configured.');

            return self::FAILURE;
        }

        try {
            $created = $catalog->sync();
        } catch (PaddleRequestFailed $exception) {
            $this->components->error('Paddle: '.$exception->reasonCode.($exception->paddleCode === null ? '' : ' ('.$exception->paddleCode.')'));

            return self::FAILURE;
        }

        foreach ($created as $price) {
            $this->line($price['price_id'].' → '.$price['provider_price_ref']);
        }

        $this->components->info(sprintf('Paddle %s prices created: %d', PaddleConfig::environment(), count($created)));

        return self::SUCCESS;
    }
}
