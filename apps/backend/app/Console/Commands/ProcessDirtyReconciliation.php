<?php

namespace App\Console\Commands;

use App\Support\Reconciliation\DirtySubjectProcessor;
use Illuminate\Console\Command;

class ProcessDirtyReconciliation extends Command
{
    protected $signature = 'reconciliation:process-dirty
        {--limit=100}
        {--lease-seconds=120}';

    protected $description = 'Reconcile due dirty orders and store unmatched-payment scans once, then update incidents.';

    public function handle(DirtySubjectProcessor $processor): int
    {
        $result = $processor->processDue(
            limit: (int) $this->option('limit'),
            leaseSeconds: (int) $this->option('lease-seconds'),
        );

        $this->components->info(sprintf(
            'Dirty reconciliation complete: claimed=%d processed=%d skipped=%d failed=%d',
            $result['claimed'],
            $result['processed'],
            $result['skipped'],
            $result['failed'],
        ));

        return self::SUCCESS;
    }
}
