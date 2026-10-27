<?php

namespace App\Support\Providers\Stripe;

use App\Models\FinancialTransaction;
use App\Models\Integration;
use App\Models\IntegrationCredential;
use App\Models\ReconciliationDirtySubject;
use App\Support\Providers\ProviderEventEmitter;
use App\Support\Reconciliation\StoreReconciliationRequeue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Polling is the guarantee, webhooks only make it faster (spec §10). Delta: objects created since the per-resource
 * watermark minus an overlap. Audit: the whole retention window. Lists are newest-first and page-bounded, so every
 * unfinished window is remembered as a backlog — its bounds and the id of the last object read — and continued after
 * that id on the next runs, as Stripe pages lists. A second, never a timestamp alone, would lose objects created in the
 * same second as the last one read. When the last object is gone, the window restarts from its second (inclusive).
 */
class StripeSync
{
    public const MODE_DELTA = 'delta';

    public const MODE_AUDIT = 'audit';

    public function __construct(
        private readonly StripeClient $client,
        private readonly StripeConnector $connector,
        private readonly StripeObjectIngestor $ingestor,
        private readonly ProviderEventEmitter $emitter,
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
                $remaining = [];

                foreach ($this->backlogs($current) as $window) {
                    $page = $this->continueWindow($apiKey, $path, $window);
                    $this->add($totals, $this->ingestor->ingest($integration, $page['objects']));

                    if (! $page['complete']) {
                        $remaining[] = [
                            'from' => $window['from'],
                            'until' => $page['until'],
                            'after' => $page['last_id'] ?? $window['after'],
                            'oldest' => $this->oldest($page['objects']) ?? $window['oldest'],
                        ];
                        $complete = false;
                    }
                }

                $from = $mode === self::MODE_AUDIT || ! isset($current['watermark'])
                    ? $retentionStart
                    : max($retentionStart, (int) $current['watermark'] - $overlap);
                $until = $now->getTimestamp();
                $page = $this->client->listCreatedSince($apiKey, $path, $from, ['created[lte]' => $until]);
                $this->add($totals, $this->ingestor->ingest($integration, $page['objects']));
                $newest = $this->newest($page['objects']);

                if ($newest !== null) {
                    $current['watermark'] = max((int) ($current['watermark'] ?? 0), $newest);
                }

                if (! $page['complete']) {
                    $remaining[] = ['from' => $from, 'until' => $until, 'after' => $page['last_id'], 'oldest' => $this->oldest($page['objects'])];
                    $complete = false;
                }

                unset($current['backlog_before'], $current['backlog_from']);
                $current['backlogs'] = $remaining;
                $resources[$resource] = $current;
            }
            $disputes = $this->disputes($integration, $apiKey, $now, $totals);
            $fees = $this->fees($integration, $apiKey, $now, $totals);
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
                'disputes' => $disputes,
                'fees' => $fees,
            ],
            'last_error' => null,
        ], ['last_successful_sync_at' => $now]);

        return ['status' => 'ok', 'emitted' => $totals['emitted'], 'unchanged' => $totals['unchanged'], 'complete' => $complete];
    }

    /**
     * @param  array{emitted: int, unchanged: int, skipped: array<string, int>}  $totals
     * @return array{state: string, checked_at: string, complete?: bool}
     */
    private function disputes(Integration $integration, string $apiKey, Carbon $now, array &$totals): array
    {
        $from = $now->copy()->subDays((int) config('watchdog.stripe.dispute_days'))->getTimestamp();

        try {
            $page = $this->client->listCreatedSince($apiKey, '/v1/disputes', $from, ['created[lte]' => $now->getTimestamp()]);
        } catch (StripeRequestFailed $failure) {
            if ($failure->reasonCode === 'stripe_permission_missing') {
                return ['state' => 'permission_missing', 'checked_at' => $now->toJSON()];
            }

            throw $failure;
        }

        $this->add($totals, $this->ingestor->ingest($integration, $page['objects']));

        return ['state' => 'ok', 'checked_at' => $now->toJSON(), 'complete' => $page['complete']];
    }

    /**
     * @param  array{emitted: int, unchanged: int, skipped: array<string, int>}  $totals
     * @return array{state: string, checked_at: string, complete?: bool}
     */
    private function fees(Integration $integration, string $apiKey, Carbon $now, array &$totals): array
    {
        $captures = FinancialTransaction::query()
            ->where('integration_id', $integration->id)
            ->where('kind', 'capture')
            ->where('status', 'succeeded')
            ->where('occurred_at', '>=', $now->copy()->subDays((int) config('watchdog.stripe.audit_days')))
            ->whereNotExists(fn ($fee) => $fee->selectRaw('1')->from('financial_transactions as fee')
                ->whereColumn('fee.integration_id', 'financial_transactions.integration_id')
                ->where('fee.kind', 'fee')
                ->whereRaw("fee.external_operation_id = 'fee:' || financial_transactions.external_operation_id"))
            ->orderByDesc('occurred_at')
            ->limit(500)
            ->get(['external_operation_id', 'metadata']);
        $budget = (int) config('watchdog.stripe.max_fees_per_run');
        $complete = true;

        foreach ($captures as $capture) {
            if (! Cache::add('stripe:fee:'.$integration->id.':'.$capture->external_operation_id, true, $now->copy()->addMinutes((int) config('watchdog.stripe.fee_retry_minutes')))) {
                continue;
            }

            if ($budget-- <= 0) {
                Cache::forget('stripe:fee:'.$integration->id.':'.$capture->external_operation_id);
                $complete = false;

                break;
            }

            try {
                $page = $this->client->get($apiKey, '/v1/balance_transactions', ['source' => $capture->external_operation_id, 'limit' => 10]);
            } catch (StripeRequestFailed $failure) {
                Cache::forget('stripe:fee:'.$integration->id.':'.$capture->external_operation_id);

                if ($failure->reasonCode === 'stripe_permission_missing') {
                    return ['state' => 'permission_missing', 'checked_at' => $now->toJSON()];
                }

                throw $failure;
            }

            $draft = $this->feeDraft($capture, is_array($page['data'] ?? null) ? $page['data'] : []);

            if ($draft !== null) {
                $this->add($totals, ['skipped' => [], ...$this->emitter->emit($integration, [$draft])]);
            }
        }

        return ['state' => 'ok', 'checked_at' => $now->toJSON(), 'complete' => $complete];
    }

    /**
     * @param  list<mixed>  $balanceTransactions
     * @return array<string, mixed>|null
     */
    private function feeDraft(FinancialTransaction $capture, array $balanceTransactions): ?array
    {
        foreach ($balanceTransactions as $row) {
            if (! is_array($row) || ! in_array($row['type'] ?? null, ['charge', 'payment'], true) || ! is_int($row['fee'] ?? null) || $row['fee'] < 0) {
                continue;
            }

            $currency = strtoupper((string) ($row['currency'] ?? ''));
            $exponent = StripeCurrency::exponent($currency);

            if ($exponent === null) {
                return null;
            }

            return [
                'type' => 'transaction.observed',
                'aggregate_type' => 'transaction',
                'aggregate_id' => 'fee:'.$capture->external_operation_id,
                'created' => (int) ($row['created'] ?? time()),
                'data' => [
                    'external_operation_id' => 'fee:'.$capture->external_operation_id,
                    'payment_external_id' => $capture->metadata['payment_external_id'] ?? null,
                    'kind' => 'fee',
                    'status' => 'succeeded',
                    'currency' => $currency,
                    'currency_exponent' => $exponent,
                    'amount_minor' => (string) $row['fee'],
                    'source_authority' => Integration::SOURCE_INDEPENDENT_PROVIDER,
                ],
            ];
        }

        return null;
    }

    /**
     * Unfinished windows of the resource; a continuation saved by an earlier release (`backlog_from`/`backlog_before`,
     * the oldest second read) is taken as a window without a cursor.
     *
     * @param  array<string, mixed>  $current
     * @return list<array{from: int, until: ?int, after: ?string, oldest: ?int}>
     */
    private function backlogs(array $current): array
    {
        $windows = [];

        foreach (is_array($current['backlogs'] ?? null) ? $current['backlogs'] : [] as $window) {
            if (is_array($window) && is_int($window['from'] ?? null)) {
                $windows[] = [
                    'from' => $window['from'],
                    'until' => is_int($window['until'] ?? null) ? $window['until'] : null,
                    'after' => is_string($window['after'] ?? null) && $window['after'] !== '' ? $window['after'] : null,
                    'oldest' => is_int($window['oldest'] ?? null) ? $window['oldest'] : null,
                ];
            }
        }

        if (isset($current['backlog_from'], $current['backlog_before'])) {
            $windows[] = ['from' => (int) $current['backlog_from'], 'until' => (int) $current['backlog_before'], 'after' => null, 'oldest' => (int) $current['backlog_before']];
        }

        return $windows;
    }

    /**
     * Continues a window after its last object. Without a cursor, or when Stripe no longer knows that object, it reads
     * again from the oldest second already read, inclusive; objects read twice are unchanged and change nothing.
     *
     * @param  array{from: int, until: ?int, after: ?string, oldest: ?int}  $window
     * @return array{objects: list<array<string, mixed>>, complete: bool, last_id: ?string, until: ?int}
     */
    private function continueWindow(string $apiKey, string $path, array $window): array
    {
        if ($window['after'] !== null) {
            try {
                return $this->client->listCreatedSince($apiKey, $path, $window['from'], array_filter(['created[lte]' => $window['until']], fn (mixed $value): bool => $value !== null), $window['after']) + ['until' => $window['until']];
            } catch (StripeRequestFailed $failure) {
                if ($failure->reasonCode !== 'stripe_request_rejected') {
                    throw $failure;
                }
            }
        }

        $until = $window['oldest'] ?? $window['until'];

        return $this->client->listCreatedSince($apiKey, $path, $window['from'], array_filter(['created[lte]' => $until], fn (mixed $value): bool => $value !== null)) + ['until' => $until];
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
