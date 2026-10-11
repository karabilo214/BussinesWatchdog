<?php

namespace App\Console\Commands;

use App\Support\Notifications\IncidentReminderPlanner;
use Illuminate\Console\Command;

class PlanNotificationReminders extends Command
{
    protected $signature = 'notifications:remind {--limit=100}';

    protected $description = 'Plan reminder notifications for still-active incidents according to channel preferences.';

    public function handle(IncidentReminderPlanner $planner): int
    {
        $planned = $planner->planDue((int) $this->option('limit'));

        $this->components->info(sprintf('Notification reminders planned: %d', $planned));

        return self::SUCCESS;
    }
}
