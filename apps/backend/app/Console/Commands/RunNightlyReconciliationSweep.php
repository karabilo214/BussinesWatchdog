<?php

namespace App\Console\Commands;

use App\Support\Reconciliation\NightlyReconciliationSweep;
use Illuminate\Console\Command;

class RunNightlyReconciliationSweep extends Command
{
    protected $signature = 'reconciliation:nightly-sweep';

    protected $description = 'Mark recent orders (90 days) and unmatched-payment scans as dirty, at most once per UTC day.';

    public function handle(NightlyReconciliationSweep $sweep): int
    {
        $result = $sweep->run();

        if ($result === null) {
            $this->components->info('Nightly sweep skipped: this UTC day window already ran or is running.');

            return self::SUCCESS;
        }

        $this->components->info(sprintf(
            'Nightly sweep complete: stores=%d orders_marked=%d',
            $result['stores'],
            $result['orders_marked'],
        ));

        return self::SUCCESS;
    }
}
