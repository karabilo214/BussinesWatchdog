<?php

namespace App\Console\Commands;

use App\Support\Integrations\ConnectorFreshness;
use Illuminate\Console\Command;

class CheckConnectorFreshness extends Command
{
    protected $signature = 'integrations:check-freshness {--limit=500}';

    protected $description = 'Mark store connectors stale (missed heartbeats) or partial (delivery backlog), open or resolve their incidents.';

    public function handle(ConnectorFreshness $freshness): int
    {
        $result = $freshness->checkAll(max(1, (int) $this->option('limit')));

        $this->components->info(sprintf('Connector freshness check complete: checked=%d changed=%d', $result['checked'], $result['changed']));

        return self::SUCCESS;
    }
}
