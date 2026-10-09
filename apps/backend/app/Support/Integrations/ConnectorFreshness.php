<?php

namespace App\Support\Integrations;

use App\Models\Incident;
use App\Models\Integration;
use App\Models\ReconciliationDirtySubject;
use App\Models\Signal;
use App\Support\Incidents\IncidentRecorder;
use App\Support\Reconciliation\StoreReconciliationRequeue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ConnectorFreshness
{
    public const STATE_WARMING_UP = 'warming_up';

    public const STATE_FRESH = 'fresh';

    public const STATE_STALE = 'stale';

    public const STATE_PARTIAL = 'partial';

    public const DEGRADED_STATES = [self::STATE_STALE, self::STATE_PARTIAL];

    public const HEARTBEAT_INTERVAL_SECONDS = 300;

    public const MISSED_HEARTBEATS = 3;

    public const DELIVERY_LAG_SECONDS = 3600;

    public const FAMILY = 'integration';

    public const COMPONENT = 'connector_freshness';

    public const TITLE_STALE = 'INTEGRATION_STALE';

    public const TITLE_DELIVERY_DELAYED = 'INTEGRATION_DELIVERY_DELAYED';

    public const REASON_STORE_DATA_STALE = 'store_data_stale';

    public const RULE_VERSION = 'v1';

    public const CONFIG_VERSION = 1;

    public function __construct(
        private readonly IncidentRecorder $incidents,
        private readonly StoreReconciliationRequeue $requeue,
    ) {}

    /**
     * @param  array<string, mixed>  $report
     */
    public function recordHeartbeat(Integration $integration, array $report): void
    {
        $heartbeat = ['received_at' => Carbon::now()->toJSON()];

        if (is_int($report['backlog_count'] ?? null) && $report['backlog_count'] >= 0) {
            $heartbeat['backlog_count'] = $report['backlog_count'];
        }

        if (is_string($report['oldest_pending_at'] ?? null) && strtotime($report['oldest_pending_at']) !== false) {
            $heartbeat['oldest_pending_at'] = Carbon::parse($report['oldest_pending_at'])->utc()->toJSON();
        }

        if (is_string($report['plugin_version'] ?? null) && $report['plugin_version'] !== '') {
            $heartbeat['plugin_version'] = mb_substr($report['plugin_version'], 0, 64);
        }

        $integration->forceFill([
            'last_heartbeat_at' => Carbon::now(),
            'health' => array_merge($integration->health ?? [], ['heartbeat' => $heartbeat]),
        ])->save();
    }

    /**
     * @return array{state: string, reason: ?string}
     */
    public function assess(Integration $integration, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $staleAfter = self::HEARTBEAT_INTERVAL_SECONDS * self::MISSED_HEARTBEATS;

        if ($integration->last_heartbeat_at === null) {
            return $integration->created_at !== null && $integration->created_at->lessThanOrEqualTo($now->copy()->subSeconds($staleAfter))
                ? ['state' => self::STATE_STALE, 'reason' => 'no_heartbeat_since_pairing']
                : ['state' => self::STATE_WARMING_UP, 'reason' => null];
        }

        if ($integration->last_heartbeat_at->lessThanOrEqualTo($now->copy()->subSeconds($staleAfter))) {
            return ['state' => self::STATE_STALE, 'reason' => 'heartbeats_missed'];
        }

        $oldestPending = $integration->health['heartbeat']['oldest_pending_at'] ?? null;

        if (is_string($oldestPending) && Carbon::parse($oldestPending)->lessThanOrEqualTo($now->copy()->subSeconds(self::DELIVERY_LAG_SECONDS))) {
            return ['state' => self::STATE_PARTIAL, 'reason' => 'delivery_backlog'];
        }

        return ['state' => self::STATE_FRESH, 'reason' => null];
    }

    /**
     * @return array{checked: int, changed: int}
     */
    public function checkAll(int $limit = 500): array
    {
        $checked = 0;
        $changed = 0;

        Integration::query()
            ->where('source_authority', Integration::SOURCE_STORE_REPORTED)
            ->whereIn('status', [Integration::STATUS_ACTIVE, Integration::STATUS_DEGRADED])
            ->orderBy('id')
            ->select('id')
            ->chunk(100, function ($chunk) use (&$checked, &$changed, $limit): bool {
                foreach ($chunk as $row) {
                    if ($checked >= $limit) {
                        return false;
                    }

                    $checked++;

                    if ($this->check($row->id)) {
                        $changed++;
                    }
                }

                return true;
            });

        return ['checked' => $checked, 'changed' => $changed];
    }

    public function check(string $integrationId): bool
    {
        return DB::transaction(function () use ($integrationId): bool {
            /** @var Integration|null $integration */
            $integration = Integration::query()->whereKey($integrationId)->lockForUpdate()->first();

            if ($integration === null || ! in_array($integration->status, [Integration::STATUS_ACTIVE, Integration::STATUS_DEGRADED], true)) {
                return false;
            }

            $now = Carbon::now();
            $assessment = $this->assess($integration, $now);
            $previous = $integration->health['freshness']['state'] ?? null;

            if ($previous === $assessment['state']) {
                return false;
            }

            $degraded = in_array($assessment['state'], self::DEGRADED_STATES, true);
            $wasDegraded = in_array($previous, self::DEGRADED_STATES, true);

            $integration->forceFill([
                'status' => $degraded ? Integration::STATUS_DEGRADED : Integration::STATUS_ACTIVE,
                'health' => array_merge($integration->health ?? [], ['freshness' => [
                    'state' => $assessment['state'],
                    'reason' => $assessment['reason'],
                    'since' => $now->toJSON(),
                ]]),
                'updated_at' => $now,
            ])->save();

            $fingerprint = $this->fingerprint($integration);

            if ($degraded) {
                $this->incidents->openOrAttach($integration->tenant_id, $integration->store_id, $fingerprint, [
                    'family' => self::FAMILY,
                    'component' => self::COMPONENT,
                    'title_code' => $assessment['state'] === self::STATE_STALE ? self::TITLE_STALE : self::TITLE_DELIVERY_DELAYED,
                    'severity' => Incident::SEVERITY_WARNING,
                    'first_bad_at' => $integration->last_heartbeat_at ?? $integration->created_at,
                    'last_good_at' => $integration->last_heartbeat_at,
                ], $this->signal($integration, $assessment, Signal::SEVERITY_WARNING, $now), 'connector_'.$assessment['state']);
            } elseif ($wasDegraded) {
                $this->incidents->resolve(
                    $integration->tenant_id,
                    $integration->store_id,
                    $fingerprint,
                    'auto_resolved_connector_fresh',
                    $this->signal($integration, $assessment, Signal::SEVERITY_INFO, $now),
                    $integration->last_heartbeat_at,
                );
                $this->requeue->requeue($integration->tenant_id, $integration->store_id, ReconciliationDirtySubject::REASON_CONNECTOR_RECOVERED);
            }

            return true;
        });
    }

    public function storeDataStale(string $tenantId, string $storeId): bool
    {
        return Integration::query()
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where('source_authority', Integration::SOURCE_STORE_REPORTED)
            ->whereIn('status', [Integration::STATUS_ACTIVE, Integration::STATUS_DEGRADED])
            ->get(['health'])
            ->contains(fn (Integration $integration): bool => in_array($integration->health['freshness']['state'] ?? null, self::DEGRADED_STATES, true));
    }

    /**
     * @param  array{state: string, reason: ?string}  $assessment
     */
    private function signal(Integration $integration, array $assessment, string $severity, Carbon $now): Signal
    {
        $heartbeat = $integration->health['heartbeat'] ?? [];

        return Signal::query()->create([
            'tenant_id' => $integration->tenant_id,
            'store_id' => $integration->store_id,
            'signal_type' => Signal::TYPE_CONNECTOR_FRESHNESS,
            'family' => self::FAMILY,
            'component' => $assessment['state'],
            'dedupe_key' => implode(':', ['connector_freshness', $integration->id, $assessment['state'], $now->toJSON()]),
            'severity' => $severity,
            'confidence' => Signal::CONFIDENCE_OBSERVED,
            'rule_version' => self::RULE_VERSION,
            'config_version' => self::CONFIG_VERSION,
            'observed_start' => $integration->last_heartbeat_at,
            'observed_end' => $now,
            'evidence' => [
                'integration_id' => $integration->id,
                'provider' => $integration->provider,
                'state' => $assessment['state'],
                'reason' => $assessment['reason'],
                'last_heartbeat_at' => $integration->last_heartbeat_at?->toJSON(),
                'backlog_count' => $heartbeat['backlog_count'] ?? null,
                'oldest_pending_at' => $heartbeat['oldest_pending_at'] ?? null,
                'missed_heartbeats_threshold' => self::MISSED_HEARTBEATS,
            ],
            'data_quality' => [],
            'detected_at' => $now,
        ]);
    }

    private function fingerprint(Integration $integration): string
    {
        return hash('sha256', implode('|', [self::FAMILY, self::COMPONENT, $integration->id]));
    }
}
