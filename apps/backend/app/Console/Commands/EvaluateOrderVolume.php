<?php

namespace App\Console\Commands;

use App\Support\Billing\OrderVolume;
use Illuminate\Console\Command;

class EvaluateOrderVolume extends Command
{
    protected $signature = 'billing:order-volume';

    protected $description = 'Refresh every store\'s three-month order average, mark stores over their package and notify their owners once';

    public function handle(OrderVolume $volume): int
    {
        $result = $volume->evaluate();
        $this->line(sprintf('stores=%d over=%d notified=%d', $result['stores'], $result['over'], $result['notified']));

        return self::SUCCESS;
    }
}
