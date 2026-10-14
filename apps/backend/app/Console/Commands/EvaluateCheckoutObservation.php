<?php

namespace App\Console\Commands;

use App\Support\Checkout\CheckoutObservationEvaluator;
use Illuminate\Console\Command;

class EvaluateCheckoutObservation extends Command
{
    protected $signature = 'checkout:observation-evaluate';

    protected $description = 'Check the checkout observation counters of Shopware stores: silent script, failing order submission (ADR 0057).';

    public function handle(CheckoutObservationEvaluator $evaluator): int
    {
        $this->components->info(sprintf('Stores evaluated: %d', $evaluator->evaluateAll()));

        return self::SUCCESS;
    }
}
