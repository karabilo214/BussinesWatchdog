<?php

namespace App\Console\Commands;

use App\Support\Checkout\ObservedPaymentAttempts;
use Illuminate\Console\Command;

class MarkStuckPaymentAttempts extends Command
{
    protected $signature = 'checkout:payment-attempts-stuck';

    protected $description = 'Count observed payment attempts without a result after 30 minutes as stuck (ADR 0056).';

    public function handle(ObservedPaymentAttempts $attempts): int
    {
        $this->components->info(sprintf('Stuck payment attempts: %d', $attempts->markStuck()));

        return self::SUCCESS;
    }
}
