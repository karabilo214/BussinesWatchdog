<?php

namespace App\Console\Commands;

use App\Support\Billing\DiscountLedger;
use App\Support\Billing\Paddle\PaddleConfig;
use Illuminate\Console\Command;

class PlanRenewalDiscounts extends Command
{
    protected $signature = 'billing:plan-discounts';

    protected $description = 'Compute the combined discount of the next billing period for subscriptions with remaining discounts and set it in Paddle';

    public function handle(DiscountLedger $ledger): int
    {
        if (! PaddleConfig::configured()) {
            $this->line('Paddle is not configured.');

            return self::SUCCESS;
        }

        $result = $ledger->planRenewals();
        $this->line(sprintf('planned=%d failed=%d', $result['planned'], $result['failed']));

        return self::SUCCESS;
    }
}
