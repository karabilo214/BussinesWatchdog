<?php

namespace App\Console\Commands;

use App\Support\Outbox\DomainOutboxDispatcher;
use Illuminate\Console\Command;

class DispatchDomainOutbox extends Command
{
    protected $signature = 'outbox:dispatch
        {--limit=50}
        {--lease-seconds=60}
        {--loop : Keep dispatching until max-iterations or max-seconds is reached}
        {--sleep-seconds=2}
        {--max-iterations=0}
        {--max-seconds=0}';

    protected $description = 'Lease and dispatch due domain outbox messages.';

    public function handle(DomainOutboxDispatcher $dispatcher): int
    {
        $limit = (int) $this->option('limit');
        $leaseSeconds = (int) $this->option('lease-seconds');

        if (! $this->option('loop')) {
            $result = $dispatcher->dispatchDue(
                limit: $limit,
                leaseSeconds: $leaseSeconds,
            );

            $this->components->info(sprintf(
                'Outbox dispatch complete: leased=%d published=%d failed=%d',
                $result['leased'],
                $result['published'],
                $result['failed'],
            ));

            return self::SUCCESS;
        }

        $sleepSeconds = max(1, min((int) $this->option('sleep-seconds'), 60));
        $maxIterations = max(0, (int) $this->option('max-iterations'));
        $maxSeconds = max(0, (int) $this->option('max-seconds'));
        $deadline = $maxSeconds > 0 ? now()->addSeconds($maxSeconds) : null;
        $iterations = 0;
        $totals = ['leased' => 0, 'published' => 0, 'failed' => 0];

        do {
            $result = $dispatcher->dispatchDue(limit: $limit, leaseSeconds: $leaseSeconds);
            $iterations++;

            foreach ($totals as $key => $value) {
                $totals[$key] = $value + $result[$key];
            }

            if ($maxIterations > 0 && $iterations >= $maxIterations) {
                break;
            }

            if ($deadline !== null && now()->greaterThanOrEqualTo($deadline)) {
                break;
            }

            if ($result['leased'] === 0) {
                sleep($sleepSeconds);
            }
        } while (true);

        $this->components->info(sprintf(
            'Outbox loop complete: iterations=%d leased=%d published=%d failed=%d',
            $iterations,
            $totals['leased'],
            $totals['published'],
            $totals['failed'],
        ));

        return self::SUCCESS;
    }
}
