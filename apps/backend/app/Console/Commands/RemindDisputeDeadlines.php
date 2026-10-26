<?php

namespace App\Console\Commands;

use App\Support\Disputes\DisputeIncidents;
use Illuminate\Console\Command;

class RemindDisputeDeadlines extends Command
{
    protected $signature = 'disputes:remind';

    protected $description = 'Remind about disputes whose answer is due at the payment provider within three days.';

    public function handle(DisputeIncidents $disputes): int
    {
        $this->components->info(sprintf('Dispute reminders requested: %d', $disputes->remindDue()));

        return self::SUCCESS;
    }
}
