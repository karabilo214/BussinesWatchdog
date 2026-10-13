<?php

namespace App\Support\Platform;

use App\Models\BillingEventInbox;
use App\Models\CheckRun;
use App\Models\DomainOutbox;
use App\Models\EventInbox;
use App\Models\NotificationDelivery;
use App\Support\Health\ReadinessChecks;
use Illuminate\Foundation\Application;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ServiceHealth
{
    public const WARN_SECONDS = 900;

    public const CRIT_SECONDS = 3600;

    public const SYNC_STALE_HOURS = 24;

    public const WORKER_SEEN_SECONDS = 300;

    public function __construct(private readonly ReadinessChecks $readiness) {}

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
            'jobs' => $this->queue(DB::table('jobs'), 'created_at', DB::table('failed_jobs')->where('failed_at', '>=', $now->copy()->subDay()), $now, true),
        ];
        $workers = DB::table('browser_workers')->where('status', 'active')->whereNull('revoked_at')->where('last_seen_at', '>=', $now->copy()->subSeconds(self::WORKER_SEEN_SECONDS))->count();
        $checks = $this->queue(DB::table('check_runs')->where('status', CheckRun::STATUS_QUEUED), 'scheduled_at', null, $now)
            + ['running' => DB::table('check_runs')->where('status', CheckRun::STATUS_RUNNING)->count(), 'workers_seen' => $workers];

        if ($checks['waiting'] > 0 && $workers === 0) {
            $checks['state'] = 'crit';
        }

        $integrations = DB::table('integrations')->where('status', 'active');
        $sync = [
            'active' => (clone $integrations)->count(),
            'stale' => (clone $integrations)->where(fn ($stale) => $stale->whereNull('last_successful_sync_at')->orWhere('last_successful_sync_at', '<', $now->copy()->subHours(self::SYNC_STALE_HOURS)))->count(),
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
    private function queue(mixed $waiting, string $column, mixed $problems, Carbon $now, bool $epoch = false): array
    {
        $count = (clone $waiting)->count();
        $oldest = (clone $waiting)->min($column);
        $age = $oldest === null ? null : max(0, (int) round($now->getTimestamp() - ($epoch ? (int) $oldest : Carbon::parse($oldest)->getTimestamp())));
        $broken = $problems === null ? 0 : $problems->count();
        $state = $age !== null && $age > self::CRIT_SECONDS ? 'crit' : (($age !== null && $age > self::WARN_SECONDS) || $broken > 0 ? 'warn' : 'ok');

        return ['waiting' => $count, 'oldest_age_seconds' => $age, 'problems' => $broken, 'state' => $state];
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
