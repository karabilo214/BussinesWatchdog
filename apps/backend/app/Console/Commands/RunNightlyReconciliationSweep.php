<?php

namespace App\Console\Commands;

use App\Support\Reconciliation\NightlyReconciliationSweep;
use Illuminate\Console\Command;

class RunNightlyReconciliationSweep extends Command
{
    protected $signature = 'reconciliation:nightly-sweep';

    protected $description = 'Queue the orders of the last 90 days whose result may have changed, the longest-unchecked thirtieth of them and every store scan, at most once per UTC day.';

    public function handle(NightlyReconciliationSweep $sweep): int
    {
        $result = $sweep->run();

        if ($result === null) {
            $this->components->info('Nightly sweep skipped: this UTC day window already ran or is running.');

            return self::SUCCESS;
        }

        $this->components->info(sprintf(
            'Nightly sweep complete: stores=%d orders_marked=%d orders_rechecked=%d',
            $result['stores'],
            $result['orders_marked'],
            $result['orders_rechecked'],
        ));

        return self::SUCCESS;
    }
}
