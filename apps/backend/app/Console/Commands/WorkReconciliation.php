<?php

namespace App\Console\Commands;

use App\Support\Reconciliation\DirtySubjectProcessor;
use Illuminate\Console\Command;

/**
 * A long-running reconciliation worker (ADR 0040): claims small batches of due dirty subjects, processes them and
 * claims again; sleeps while nothing is due. Any number of workers can run side by side — a claim skips rows another
 * worker holds — and each one exits after `--max-time` or on SIGTERM so the supervisor restarts it fresh.
 */
class WorkReconciliation extends Command
{
    protected $signature = 'reconciliation:work
        {--batch=25 : Subjects claimed at once}
        {--lease-seconds=300 : How long a claim is held before another worker may take it}
        {--idle-sleep=2 : Seconds to wait when nothing is due}
        {--max-time=3600 : Exit after this many seconds}
        {--max-batches=0 : Exit after this many batches (0: no limit)}
        {--stop-when-empty : Exit as soon as nothing is due}';

    protected $description = 'Process due reconciliation subjects continuously; run several for parallel processing.';

    private bool $stopping = false;

    public function handle(DirtySubjectProcessor $processor): int
    {
        if (extension_loaded('pcntl')) {
            $this->trap([SIGTERM, SIGINT, SIGQUIT], function (): void {
                $this->stopping = true;
            });
        }

        $started = time();
        $batches = 0;
        $totals = ['claimed' => 0, 'processed' => 0, 'unchanged' => 0, 'skipped' => 0, 'failed' => 0];
        $maxTime = max(1, (int) $this->option('max-time'));
        $maxBatches = max(0, (int) $this->option('max-batches'));

        while (! $this->stopping && time() - $started < $maxTime && ($maxBatches === 0 || $batches < $maxBatches) && memory_get_usage(true) < 256 * 1024 * 1024) {
            $result = $processor->processDue((int) $this->option('batch'), (int) $this->option('lease-seconds'));
            $batches++;

            foreach ($totals as $key => $value) {
                $totals[$key] = $value + $result[$key];
            }

            if ($result['claimed'] === 0) {
                if ($this->option('stop-when-empty')) {
                    break;
                }

                sleep(max(1, (int) $this->option('idle-sleep')));
            }
        }

        $this->line(sprintf('reconciliation worker stopped: batches=%d claimed=%d processed=%d unchanged=%d skipped=%d failed=%d', $batches, ...array_values($totals)));

        return self::SUCCESS;
    }
}
