<?php

namespace App\Support\Providers\Shopware;

use App\Models\Integration;
use App\Models\Order;
use App\Models\ReconciliationDirtySubject;
use App\Support\Providers\ProviderEventEmitter;
use App\Support\Reconciliation\StoreReconciliationRequeue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Polls a Shopware store's orders through the Admin API search (ADR 0026). A new order has no `updatedAt` and a
 * payment state change only touches the transaction, so the delta asks for orders created, updated or with a
 * transaction updated since the watermark (minus an overlap). The daily audit re-reads the retention window and asks
 * Shopware which known orders still exist, so deleted orders are found. Every run has a request budget and one run per
 * store at a time.
 */
class ShopwareSync
{
    public const MODE_DELTA = 'delta';

    public const MODE_AUDIT = 'audit';

    private int $budget = 0;

    /** @var array{emitted: int, unchanged: int, skipped: array<string, int>} */
    private array $totals = ['emitted' => 0, 'unchanged' => 0, 'skipped' => []];

    public function __construct(
        private readonly ShopwareClient $client,
        private readonly ShopwareConnector $connector,
        private readonly ShopwareOrderMapper $mapper,
        private readonly ProviderEventEmitter $emitter,
        private readonly StoreReconciliationRequeue $requeue,
    ) {}

    public function syncable(Integration $integration): bool
    {
        return $integration->provider === ShopwareConnector::PROVIDER
            && in_array($integration->status, [Integration::STATUS_ACTIVE, Integration::STATUS_DEGRADED], true)
            && ! isset($integration->health['key_rejected_at']);
    }

    /**
     * @return array{status: string, emitted: int, unchanged: int, complete: bool, error?: string}
     */
    public function run(Integration $integration, string $mode = self::MODE_DELTA): array
    {
        if (! $this->syncable($integration)) {
            return ['status' => 'skipped', 'emitted' => 0, 'unchanged' => 0, 'complete' => false];
        }

        $lock = Cache::lock('shopware-sync:'.$integration->id, 900);

        if (! $lock->get()) {
            return ['status' => 'skipped', 'emitted' => 0, 'unchanged' => 0, 'complete' => false];
        }

        try {
            return $this->runLocked($integration, $mode);
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{status: string, emitted: int, unchanged: int, complete: bool, error?: string}
     */
    private function runLocked(Integration $integration, string $mode): array
    {
        $state = is_array($integration->health['sync'] ?? null) ? $integration->health['sync'] : [];
        $now = Carbon::now()->startOfSecond();
        $this->budget = (int) config('watchdog.shopware.max_requests_per_run');
        $this->totals = ['emitted' => 0, 'unchanged' => 0, 'skipped' => []];
        $credentials = $this->connector->credentials($integration);
        $baseUrl = (string) $integration->store()->value('base_url');
        $retentionStart = $now->copy()->subDays((int) config('watchdog.shopware.audit_days'));
        $next = $state;

        try {
            if ($credentials === null) {
                throw new ShopwareRequestFailed('shopware_key_rejected', 401);
            }

            if (! $this->spend()) {
                return $this->fail($integration, new ShopwareRequestFailed('shopware_budget_exhausted'));
            }

            $token = $this->client->token($baseUrl, $credentials['access_key'], $credentials['secret'])['token'];

            if ($mode === self::MODE_DELTA) {
                $from = isset($state['watermark'])
                    ? Carbon::parse($state['watermark'])->subMinutes((int) config('watchdog.shopware.delta_overlap_minutes'))->max($retentionStart)
                    : $retentionStart;
                $since = $from->format('Y-m-d\TH:i:s.v\Z');
                $walk = $this->walk($integration, $baseUrl, $token, [[
                    'type' => 'multi',
                    'operator' => 'OR',
                    'queries' => [
                        ['type' => 'range', 'field' => 'updatedAt', 'parameters' => ['gte' => $since]],
                        ['type' => 'range', 'field' => 'createdAt', 'parameters' => ['gte' => $since]],
                        ['type' => 'range', 'field' => 'transactions.updatedAt', 'parameters' => ['gte' => $since]],
                    ],
                ]]);
                $complete = $walk['complete'];

                if ($complete) {
                    $previous = isset($state['watermark']) ? Carbon::parse($state['watermark']) : null;
                    $furthest = $walk['last_changed'] !== null && ($previous === null || $walk['last_changed']->greaterThan($previous)) ? $walk['last_changed'] : $previous;
                    $next['watermark'] = ($furthest ?? $now)->min($now)->toJSON();
                }
            } else {
                $complete = $this->walk($integration, $baseUrl, $token, [[
                    'type' => 'range', 'field' => 'createdAt', 'parameters' => ['gte' => $retentionStart->format('Y-m-d\TH:i:s.v\Z')],
                ]])['complete'];

                if ($complete) {
                    $next['deletion_cursor'] = $this->findDeleted($integration, $baseUrl, $token, $retentionStart, $state['deletion_cursor'] ?? null);
                    $complete = $next['deletion_cursor'] === null;
                }

                $next['last_audit_at'] = $now->toJSON();
            }
        } catch (ShopwareRequestFailed $failure) {
            return $this->fail($integration, $failure);
        }

        $this->updateHealth($integration, [
            'sync' => [
                ...array_filter($next, fn (mixed $value): bool => $value !== null),
                'last_run_at' => $now->toJSON(),
                'last_mode' => $mode,
                'emitted' => $this->totals['emitted'],
                'unchanged' => $this->totals['unchanged'],
                'skipped' => $this->totals['skipped'],
                'complete' => $complete,
            ],
            'last_error' => null,
        ], ['last_successful_sync_at' => $now]);

        return ['status' => 'ok', 'emitted' => $this->totals['emitted'], 'unchanged' => $this->totals['unchanged'], 'complete' => $complete];
    }

    /**
     * @param  list<array<string, mixed>>  $filter
     * @return array{complete: bool, last_changed: ?Carbon}
     */
    private function walk(Integration $integration, string $baseUrl, string $token, array $filter): array
    {
        $lastChanged = null;
        $limit = (int) config('watchdog.shopware.page_size');

        for ($page = 1; ; $page++) {
            if (! $this->spend()) {
                return ['complete' => false, 'last_changed' => $lastChanged];
            }

            $result = $this->client->search($baseUrl, $token, '/api/search/order', [
                ...ShopwareOrderMapper::criteria(),
                'filter' => $filter,
                'sort' => [['field' => 'createdAt', 'order' => 'ASC'], ['field' => 'id', 'order' => 'ASC']],
                'limit' => $limit,
                'page' => $page,
            ]);
            $orders = array_values(array_filter(is_array($result['data'] ?? null) ? $result['data'] : [], 'is_array'));
            $drafts = [];

            foreach ($orders as $order) {
                $mapped = $this->mapper->order($order);

                if ($mapped['draft'] === null) {
                    $this->totals['skipped'][(string) $mapped['skipped']] = ($this->totals['skipped'][(string) $mapped['skipped']] ?? 0) + 1;

                    continue;
                }

                $drafts[] = $mapped['draft'];
                $changed = $mapped['draft']['data']['source_updated_at'] ?? null;

                if (is_string($changed) && ($lastChanged === null || Carbon::parse($changed)->greaterThan($lastChanged))) {
                    $lastChanged = Carbon::parse($changed);
                }
            }

            if ($drafts !== []) {
                $summary = $this->emitter->emit($integration, $drafts);
                $this->totals['emitted'] += $summary['emitted'];
                $this->totals['unchanged'] += $summary['unchanged'];
            }

            if (count($orders) < $limit) {
                return ['complete' => true, 'last_changed' => $lastChanged];
            }
        }
    }

    /**
     * Known orders of the retention window that Shopware no longer has are deleted; asked for by id, 100 at a time.
     * Returns the cursor to continue from, or null when every known order was checked.
     */
    private function findDeleted(Integration $integration, string $baseUrl, string $token, Carbon $retentionStart, ?string $cursor): ?string
    {
        while (true) {
            $ids = Order::query()
                ->where('tenant_id', $integration->tenant_id)
                ->where('integration_id', $integration->id)
                ->whereNull('deleted_at')
                ->where('source_created_at', '>=', $retentionStart)
                ->when($cursor !== null, fn ($query) => $query->where('external_id', '>', $cursor))
                ->orderBy('external_id')
                ->limit(100)
                ->pluck('external_id')
                ->map(fn (mixed $id): string => (string) $id)
                ->all();

            if ($ids === []) {
                return null;
            }

            if (! $this->spend()) {
                return $cursor;
            }

            $present = array_flip(array_map('strval', (array) ($this->client->search($baseUrl, $token, '/api/search-ids/order', ['ids' => $ids])['data'] ?? [])));
            $drafts = array_map(fn (string $id): array => $this->mapper->deletedOrder($id), array_values(array_filter($ids, fn (string $id): bool => ! isset($present[$id]))));

            if ($drafts !== []) {
                $summary = $this->emitter->emit($integration, $drafts);
                $this->totals['emitted'] += $summary['emitted'];
                $this->totals['unchanged'] += $summary['unchanged'];
            }

            $cursor = end($ids);
        }
    }

    private function spend(): bool
    {
        if ($this->budget <= 0) {
            return false;
        }

        $this->budget--;
        $pause = (int) config('watchdog.shopware.request_pause_ms');

        if ($pause > 0) {
            usleep($pause * 1000);
        }

        return true;
    }

    /**
     * @return array{status: string, emitted: int, unchanged: int, complete: bool, error: string}
     */
    private function fail(Integration $integration, ShopwareRequestFailed $failure): array
    {
        $error = ['code' => $failure->reasonCode, 'at' => Carbon::now()->toJSON(), 'retry_after_seconds' => $failure->retryAfterSeconds];

        if ($failure->credentialsUnusable()) {
            $this->updateHealth($integration, ['last_error' => $error, 'key_rejected_at' => Carbon::now()->toJSON()], ['status' => Integration::STATUS_DEGRADED]);
            $this->requeue->requeue($integration->tenant_id, $integration->store_id, ReconciliationDirtySubject::REASON_PROVIDER_COVERAGE_CHANGED);
        } else {
            $this->updateHealth($integration, ['last_error' => $error], []);
        }

        return ['status' => 'failed', 'emitted' => $this->totals['emitted'], 'unchanged' => $this->totals['unchanged'], 'complete' => false, 'error' => $failure->reasonCode];
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
}
