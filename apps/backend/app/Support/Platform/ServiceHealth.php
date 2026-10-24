<?php

namespace App\Support\Platform;

use App\Jobs\SyncIntegration;
use App\Models\BillingEventInbox;
use App\Models\CheckRun;
use App\Models\DomainOutbox;
use App\Models\EventInbox;
use App\Models\NotificationDelivery;
use App\Support\Billing\StoreCapacity;
use App\Support\Health\ReadinessChecks;
use App\Support\Integrations\OrderSources;
use Illuminate\Foundation\Application;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

class ServiceHealth
{
    public const WARN_SECONDS = 900;

    public const CRIT_SECONDS = 3600;

    public const SYNC_STALE_HOURS = 24;

    public const WORKER_SEEN_SECONDS = 300;

    /** Jobs waiting this long with no worker alive are a problem, not a worker restart in progress. */
    public const NO_WORKER_GRACE_SECONDS = 300;

    public function __construct(private readonly ReadinessChecks $readiness, private readonly QueueWorkerHeartbeats $workers) {}

    /**
     * @return array<string, mixed>
     */
    public function build(?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $components = array_map(fn (array $check): string => $check['ok'] ? 'ok' : 'crit', $this->readiness->all());
        $queues = [
            'event_inbox' => $this->queue(DB::table('event_inbox')->whereIn('status', [EventInbox::STATUS_RECEIVED, EventInbox::STATUS_PROCESSING]), 'received_at', DB::table('event_inbox')->whereIn('status', [EventInbox::STATUS_DEAD_LETTER, EventInbox::STATUS_QUARANTINED]), $now),
            'domain_outbox' => $this->queue(DB::table('domain_outbox')->whereIn('status', [DomainOutbox::STATUS_PENDING, DomainOutbox::STATUS_LEASED]), 'created_at', DB::table('domain_outbox')->where('status', DomainOutbox::STATUS_DEAD_LETTER), $now),
            'billing_events' => $this->queue(DB::table('billing_event_inbox')->whereIn('status', [BillingEventInbox::STATUS_RECEIVED, BillingEventInbox::STATUS_PROCESSING]), 'received_at', DB::table('billing_event_inbox')->whereIn('status', [BillingEventInbox::STATUS_DEAD_LETTER, BillingEventInbox::STATUS_QUARANTINED]), $now)
                + ['last_received_at' => $this->time(DB::table('billing_event_inbox')->max('received_at'))],
            'reconciliation' => $this->queue(DB::table('reconciliation_dirty_subjects'), 'due_at', null, $now),
            'notifications' => $this->queue(DB::table('notification_deliveries')->whereIn('status', [NotificationDelivery::STATUS_QUEUED, NotificationDelivery::STATUS_SENDING]), 'created_at', DB::table('notification_deliveries')->whereIn('status', [NotificationDelivery::STATUS_FAILED, NotificationDelivery::STATUS_DEAD_LETTER])->where('created_at', '>=', $now->copy()->subDay()), $now),
            'jobs' => $this->jobs($now),
        ];
        $workers = DB::table('browser_workers')->where('status', 'active')->whereNull('revoked_at')->where('last_seen_at', '>=', $now->copy()->subSeconds(self::WORKER_SEEN_SECONDS))->count();
        $checks = $this->queue(DB::table('check_runs')->where('status', CheckRun::STATUS_QUEUED), 'scheduled_at', null, $now)
            + ['running' => DB::table('check_runs')->where('status', CheckRun::STATUS_RUNNING)->count(), 'workers_seen' => $workers];

        if ($checks['waiting'] > 0 && $workers === 0) {
            $checks['state'] = 'crit';
        }

        $integrations = DB::table('integrations')->where('status', 'active');
        $staleBefore = $now->copy()->subHours(self::SYNC_STALE_HOURS);
        $pushed = array_values(array_diff(OrderSources::PROVIDERS, OrderSources::POLLED));
        $sync = [
            'active' => (clone $integrations)->count(),
            'stale' => (clone $integrations)
                ->whereNotIn('store_id', StoreCapacity::pausedStoreIds())
                ->where(fn ($stale) => $stale
                    ->where(fn ($plugin) => $plugin->whereIn('provider', $pushed)->where(fn ($seen) => $seen->whereNull('last_heartbeat_at')->orWhere('last_heartbeat_at', '<', $staleBefore)))
                    ->orWhere(fn ($api) => $api->whereNotIn('provider', $pushed)->where(fn ($synced) => $synced->whereNull('last_successful_sync_at')->orWhere('last_successful_sync_at', '<', $staleBefore))))
                ->count(),
        ];
        $sync['state'] = $sync['stale'] > 0 ? 'warn' : 'ok';

        return [
            'as_of' => $now->toJSON(),
            'state' => $this->worst([...array_values($components), ...array_column($queues, 'state'), $checks['state'], $sync['state']]),
            'components' => $components,
            'queues' => $queues,
            'browser_checks' => $checks,
            'connector_sync' => $sync,
            'thresholds' => ['warn_seconds' => self::WARN_SECONDS, 'crit_seconds' => self::CRIT_SECONDS, 'sync_stale_hours' => self::SYNC_STALE_HOURS],
            'version' => [
                'app' => (string) config('watchdog.release', 'dev'),
                'environment' => (string) config('app.env'),
                'php' => PHP_VERSION,
                'laravel' => Application::VERSION,
            ],
        ];
    }

    /**
     * @return array{waiting: int, oldest_age_seconds: ?int, problems: int, state: string}
     */
    private function queue(mixed $waiting, string $column, mixed $problems, Carbon $now): array
    {
        $count = (clone $waiting)->count();
        $oldest = (clone $waiting)->min($column);
        $age = $oldest === null ? null : max(0, (int) round($now->getTimestamp() - Carbon::parse($oldest)->getTimestamp()));
        $broken = $problems === null ? 0 : $problems->count();
        $state = $age !== null && $age > self::CRIT_SECONDS ? 'crit' : (($age !== null && $age > self::WARN_SECONDS) || $broken > 0 ? 'warn' : 'ok');

        return ['waiting' => $count, 'oldest_age_seconds' => $age, 'problems' => $broken, 'state' => $state];
    }

    /**
     * The queue the workers really take jobs from (`QUEUE_CONNECTION`, Redis in production): connector sync jobs and
     * anything on the default queue. Pending, delayed (released, e.g. waiting for the same integration) and reserved
     * (running) jobs, the age of the oldest pending one, failed jobs of the last day and the workers seen alive. A
     * backend that answers is not enough: pending jobs with no worker alive is a problem.
     *
     * @return array<string, mixed>
     */
    private function jobs(Carbon $now): array
    {
        $connection = (string) config('queue.default');
        $queues = array_values(array_unique([SyncIntegration::QUEUE, (string) (config("queue.connections.{$connection}.queue") ?? 'default')]));
        $failed = DB::table('failed_jobs')->where('failed_at', '>=', $now->copy()->subDay())->count();
        $workers = $this->workers->summary($connection, $queues, $now);
        $base = ['connection' => $connection, 'queues' => $queues, 'problems' => $failed, 'workers_seen' => $workers['alive'], 'last_processed_at' => $workers['last_job_at']];

        try {
            $queue = Queue::connection($connection);
            $pending = $delayed = $reserved = 0;
            $oldest = null;

            foreach ($queues as $name) {
                $pending += (int) $queue->pendingSize($name);
                $delayed += (int) $queue->delayedSize($name);
                $reserved += (int) $queue->reservedSize($name);
                $created = $queue->creationTimeOfOldestPendingJob($name);
                $oldest = is_numeric($created) ? min($oldest ?? (int) $created, (int) $created) : $oldest;
            }
        } catch (Throwable $exception) {
            report($exception);

            return $base + ['waiting' => 0, 'delayed' => 0, 'reserved' => 0, 'oldest_age_seconds' => null, 'state' => 'crit', 'error' => 'queue_unavailable'];
        }

        $age = $oldest === null ? null : max(0, $now->getTimestamp() - $oldest);
        $state = match (true) {
            $age !== null && $age > self::CRIT_SECONDS => 'crit',
            $pending > 0 && $workers['alive'] === 0 && $age !== null && $age > self::NO_WORKER_GRACE_SECONDS => 'crit',
            ($age !== null && $age > self::WARN_SECONDS) || $failed > 0 => 'warn',
            default => 'ok',
        };

        return $base + ['waiting' => $pending, 'delayed' => $delayed, 'reserved' => $reserved, 'oldest_age_seconds' => $age, 'state' => $state];
    }

    private function time(mixed $value): ?string
    {
        return $value === null ? null : Carbon::parse($value)->toJSON();
    }

    /**
     * @param  list<string>  $states
     */
    private function worst(array $states): string
    {
        return in_array('crit', $states, true) ? 'crit' : (in_array('warn', $states, true) ? 'warn' : 'ok');
    }
}
