<?php

namespace App\Console\Commands;

use App\Support\Billing\Paddle\PaddleConfig;
use App\Support\Billing\Paddle\PaddleSubscriptionActions;
use Illuminate\Console\Command;

class SettlePlanChanges extends Command
{
    protected $signature = 'billing:plan-changes';

    protected $description = 'Apply plan changes scheduled for the next period once it starts, settle changes with an unknown Paddle outcome and re-plan next-period discounts';

    public function handle(PaddleSubscriptionActions $actions): int
    {
        if (! PaddleConfig::configured()) {
            $this->line('Paddle is not configured.');

            return self::SUCCESS;
        }

        $result = $actions->settle();
        $this->line(sprintf('applied=%d resolved=%d discounts=%d', $result['applied'], $result['resolved'], $result['discounts']));

        return self::SUCCESS;
    }
}
