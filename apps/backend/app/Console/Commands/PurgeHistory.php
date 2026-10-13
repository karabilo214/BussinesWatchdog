<?php

namespace App\Console\Commands;

use App\Support\Billing\HistoryPurger;
use Illuminate\Console\Command;

class PurgeHistory extends Command
{
    protected $signature = 'billing:history-purge';

    protected $description = 'Delete history that has been outside the package retention for 30 days (incidents, signals, past reconciliation results, check runs, attempt windows, deliveries)';

    public function handle(HistoryPurger $purger): int
    {
        $this->line(collect($purger->purge())->map(fn (int $count, string $key): string => "{$key}={$count}")->implode(' '));

        return self::SUCCESS;
    }
}
