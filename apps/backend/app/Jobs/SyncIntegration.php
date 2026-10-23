<?php

namespace App\Jobs;

use App\Models\Integration;
use App\Support\Integrations\IntegrationSyncRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

/**
 * One integration synced in one mode by a queue worker (ADR 0040). The scheduler commands only hand these out, so
 * several workers sync different integrations at the same time; the same integration is never synced twice at once
 * (any mode), and the same integration and mode is queued at most once.
 */
class SyncIntegration implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const QUEUE = 'sync';

    public int $timeout = 840;

    public int $tries = 20;

    public int $uniqueFor = 1800;

    public function __construct(
        public readonly string $integrationId,
        public readonly string $mode,
    ) {
        $this->onQueue(self::QUEUE);
    }

    public function uniqueId(): string
    {
        return $this->integrationId.':'.$this->mode;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('integration-sync:'.$this->integrationId))->releaseAfter(60)->expireAfter(900)];
    }

    public function handle(IntegrationSyncRunner $runner): void
    {
        /** @var Integration|null $integration */
        $integration = Integration::query()->find($this->integrationId);

        if ($integration === null || ! $runner->eligible($integration)) {
            return;
        }

        $result = $runner->run($integration, $this->mode);
        $runner->recordScheduledSuccess($integration, $result);
    }
}
