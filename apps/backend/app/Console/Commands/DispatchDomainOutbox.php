<?php

namespace App\Console\Commands;

use App\Support\Outbox\DomainOutboxDispatcher;
use Illuminate\Console\Command;

class DispatchDomainOutbox extends Command
{
    protected $signature = 'outbox:dispatch {--limit=50} {--lease-seconds=60}';

    protected $description = 'Lease and dispatch due domain outbox messages.';

    public function handle(DomainOutboxDispatcher $dispatcher): int
    {
        $result = $dispatcher->dispatchDue(
            limit: (int) $this->option('limit'),
            leaseSeconds: (int) $this->option('lease-seconds'),
        );

        $this->components->info(sprintf(
            'Outbox dispatch complete: leased=%d published=%d failed=%d',
            $result['leased'],
            $result['published'],
            $result['failed'],
        ));

        return self::SUCCESS;
    }
}
