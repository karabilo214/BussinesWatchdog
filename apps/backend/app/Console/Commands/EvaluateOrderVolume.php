<?php

namespace App\Console\Commands;

use App\Support\Billing\OrderVolume;
use Illuminate\Console\Command;

class EvaluateOrderVolume extends Command
{
    protected $signature = 'billing:order-volume';

    protected $description = 'Refresh every store\'s three-month order average, mark stores over their package, notify owners once, pause organisations after the grace period and resume them when the package covers the volume';

    public function handle(OrderVolume $volume): int
    {
        $result = $volume->evaluate();
        $this->line(sprintf('stores=%d over=%d notified=%d paused=%d resumed=%d', $result['stores'], $result['over'], $result['notified'], $result['paused'], $result['resumed']));

        return self::SUCCESS;
    }
}
