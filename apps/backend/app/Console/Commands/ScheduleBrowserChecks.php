<?php

namespace App\Console\Commands;

use App\Support\Browser\BrowserLeaseService;
use App\Support\Browser\CheckScheduler;
use Illuminate\Console\Command;

class ScheduleBrowserChecks extends Command
{
    protected $signature = 'browser:schedule {--limit=200}';

    protected $description = 'Create due browser check runs and recover attempts whose lease expired.';

    public function handle(CheckScheduler $scheduler, BrowserLeaseService $leases): int
    {
        $recovered = $leases->recoverExpired();
        $result = $scheduler->scheduleDue(max(1, (int) $this->option('limit')));

        $this->components->info(sprintf('Browser checks: due=%d created=%d recovered=%d', $result['due'], $result['created'], $recovered));

        return self::SUCCESS;
    }
}
