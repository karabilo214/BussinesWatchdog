<?php

namespace App\Console\Commands\Concerns;

use App\Models\Integration;
use App\Support\Integrations\IntegrationSyncRunner;

/**
 * Hands one sync job per integration to the queue workers; `--inline` syncs them one after another in this process
 * instead (for a manual run without workers).
 */
trait QueuesIntegrationSyncs
{
    protected function queueSyncs(IntegrationSyncRunner $runner, string $provider, string $mode): int
    {
        if (! $this->option('inline')) {
            $result = $runner->dispatch($provider, $mode);
            $this->line(sprintf('%s %s requested=%d', $provider, $mode, $result['requested']));

            return self::SUCCESS;
        }

        foreach ($runner->due($provider)->get() as $integration) {
            /** @var Integration $integration */
            $result = $runner->run($integration, $mode);
            $this->line(sprintf('%s %s emitted=%d unchanged=%d complete=%s%s', $integration->id, $result['status'], $result['emitted'], $result['unchanged'], $result['complete'] ? 'yes' : 'no', isset($result['error']) ? ' error='.$result['error'] : ''));
        }

        return self::SUCCESS;
    }
}
