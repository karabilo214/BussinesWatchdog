<?php

namespace App\Support\Providers\Stripe;

use App\Models\Integration;
use App\Models\IntegrationCredential;
use App\Models\ReconciliationDirtySubject;
use App\Support\Reconciliation\StoreReconciliationRequeue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Polling is the guarantee, webhooks only make it faster (spec §10). Delta: objects created since the per-resource
 * watermark minus an overlap. Audit: the whole retention window. Lists are newest-first and page-bounded, so an
 * unfinished window is remembered as a backlog and continued on the next run instead of advancing the watermark.
 */
class StripeSync
{
    public const MODE_DELTA = 'delta';

    public const MODE_AUDIT = 'audit';

    public function __construct(
        private readonly StripeClient $client,
        private readonly StripeConnector $connector,
        private readonly StripeObjectIngestor $ingestor,
        private readonly StoreReconciliationRequeue $requeue,
    ) {}

    /**
     * @return array{status: string, emitted: int, unchanged: int, complete: bool, error?: string}
     */
    public function run(Integration $integration, string $mode = self::MODE_DELTA): array
    {
        if ($integration->provider !== 'stripe' || $integration->status !== Integration::STATUS_ACTIVE) {
            return ['status' => 'skipped', 'emitted' => 0, 'unchanged' => 0, 'complete' => false];
        }

        $apiKey = $this->connector->secret($integration, IntegrationCredential::KIND_STRIPE_API);

        if ($apiKey === null) {
            return $this->fail($integration, new StripeRequestFailed('stripe_key_rejected', 401));
        }

        $state = is_array($integration->health['sync'] ?? null) ? $integration->health['sync'] : [];
        $resources = is_array($state['resources'] ?? null) ? $state['resources'] : [];
        $now = Carbon::now();
        $retentionStart = $now->copy()->subDays((int) config('watchdog.stripe.audit_days'))->getTimestamp();
        $overlap = (int) config('watchdog.stripe.delta_overlap_minutes') * 60;
        $totals = ['emitted' => 0, 'unchanged' => 0, 'skipped' => []];
        $complete = true;

        try {
            foreach (StripeConnector::READ_PROBES as $resource => $path) {
                $current = is_array($resources[$resource] ?? null) ? $resources[$resource] : [];

                if (isset($current['backlog_before'], $current['backlog_from'])) {
                    $page = $this->client->listCreatedSince($apiKey, $path, (int) $current['backlog_from'], ['created[lt]' => (int) $current['backlog_before']]);
                    $this->add($totals, $this->ingestor->ingest($integration, $page['objects']));

                    if ($page['complete']) {
                        unset($current['backlog_before'], $current['backlog_from']);
                    } else {
                        $current['backlog_before'] = $this->oldest($page['objects']) ?? $current['backlog_before'];
                        $complete = false;
                    }
                }

                $from = $mode === self::MODE_AUDIT || ! isset($current['watermark'])
                    ? $retentionStart
                    : max($retentionStart, (int) $current['watermark'] - $overlap);
                $page = $this->client->listCreatedSince($apiKey, $path, $from);
                $this->add($totals, $this->ingestor->ingest($integration, $page['objects']));
                $newest = $this->newest($page['objects']);

                if ($newest !== null) {
                    $current['watermark'] = max((int) ($current['watermark'] ?? 0), $newest);
                }

                if (! $page['complete'] && ! isset($current['backlog_before'])) {
                    $current['backlog_from'] = $from;
                    $current['backlog_before'] = $this->oldest($page['objects']);
                    $complete = false;
                }

                $resources[$resource] = $current;
            }
        } catch (StripeRequestFailed $failure) {
            return $this->fail($integration, $failure);
        }

        $this->updateHealth($integration, [
            'sync' => [
                'resources' => $resources,
                'last_run_at' => $now->toJSON(),
                'last_mode' => $mode,
                'last_audit_at' => $mode === self::MODE_AUDIT ? $now->toJSON() : ($state['last_audit_at'] ?? null),
                'emitted' => $totals['emitted'],
                'unchanged' => $totals['unchanged'],
                'skipped' => $totals['skipped'],
                'complete' => $complete,
            ],
            'last_error' => null,
        ], ['last_successful_sync_at' => $now]);

        return ['status' => 'ok', 'emitted' => $totals['emitted'], 'unchanged' => $totals['unchanged'], 'complete' => $complete];
    }

    /**
     * A rejected key stops all calls (status degraded, money checks fall back to unknown); other failures are kept
     * in health and retried on the next run.
     *
     * @return array{status: string, emitted: int, unchanged: int, complete: bool, error: string}
     */
    private function fail(Integration $integration, StripeRequestFailed $failure): array
    {
        $error = ['code' => $failure->reasonCode, 'at' => Carbon::now()->toJSON(), 'retry_after_seconds' => $failure->retryAfterSeconds];

        if ($failure->keyUnusable()) {
            $this->updateHealth($integration, ['last_error' => $error, 'key_rejected_at' => Carbon::now()->toJSON()], ['status' => Integration::STATUS_DEGRADED]);
            $this->requeue->requeue($integration->tenant_id, $integration->store_id, ReconciliationDirtySubject::REASON_PROVIDER_COVERAGE_CHANGED);
        } else {
            $this->updateHealth($integration, ['last_error' => $error], []);
        }

        return ['status' => 'failed', 'emitted' => 0, 'unchanged' => 0, 'complete' => false, 'error' => $failure->reasonCode];
    }

    /**
     * @param  array<string, mixed>  $health
     * @param  array<string, mixed>  $attributes
     */
    private function updateHealth(Integration $integration, array $health, array $attributes): void
    {
        DB::transaction(function () use ($integration, $health, $attributes): void {
            /** @var Integration $locked */
            $locked = Integration::query()->whereKey($integration->id)->lockForUpdate()->firstOrFail();
            $locked->forceFill([...$attributes, 'health' => array_merge($locked->health ?? [], $health), 'updated_at' => Carbon::now()])->save();
        });

        $integration->refresh();
    }

    /**
     * @param  array{emitted: int, unchanged: int, skipped: array<string, int>}  $totals
     * @param  array{emitted: int, unchanged: int, skipped: array<string, int>}  $summary
     */
    private function add(array &$totals, array $summary): void
    {
        $totals['emitted'] += $summary['emitted'];
        $totals['unchanged'] += $summary['unchanged'];

        foreach ($summary['skipped'] as $reason => $count) {
            $totals['skipped'][$reason] = ($totals['skipped'][$reason] ?? 0) + $count;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $objects
     */
    private function newest(array $objects): ?int
    {
        $created = array_filter(array_map(fn (array $object): mixed => $object['created'] ?? null, $objects), 'is_int');

        return $created === [] ? null : max($created);
    }

    /**
     * @param  list<array<string, mixed>>  $objects
     */
    private function oldest(array $objects): ?int
    {
        $created = array_filter(array_map(fn (array $object): mixed => $object['created'] ?? null, $objects), 'is_int');

        return $created === [] ? null : min($created);
    }
}
