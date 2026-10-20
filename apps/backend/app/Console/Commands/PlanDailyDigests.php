<?php

namespace App\Console\Commands;

use App\Support\Notifications\DailyDigestPlanner;
use Illuminate\Console\Command;

class PlanDailyDigests extends Command
{
    protected $signature = 'notifications:digest';

    protected $description = 'Plan the daily digest of every channel whose local digest time has come today.';

    public function handle(DailyDigestPlanner $planner): int
    {
        $this->components->info(sprintf('Daily digests planned: %d', $planner->planDue()));

        return self::SUCCESS;
    }
}
