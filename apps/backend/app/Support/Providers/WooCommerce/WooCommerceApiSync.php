<?php

namespace App\Support\Providers\WooCommerce;

use App\Models\Integration;
use App\Models\Order;
use App\Models\ReconciliationDirtySubject;
use App\Models\Refund;
use App\Support\Providers\ProviderEventEmitter;
use App\Support\Reconciliation\StoreReconciliationRequeue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Polls a store's orders through its WooCommerce REST API (ADR 0024). Delta reads orders modified since the
 * watermark (minus an overlap), recent re-reads orders created in the last hours (WooCommerce before 7.9 does not
 * touch an order when a refund is added), audit re-reads the retention window and asks for every known order that
 * was not listed, so deleted orders are found. Refunds of an order are read only when its refund summary changed.
 * Every run has a request budget and one run per store at a time.
 */
class WooCommerceApiSync
{
    public const MODE_DELTA = 'delta';

    public const MODE_RECENT = 'recent';

    public const MODE_AUDIT = 'audit';

    private int $budget = 0;

    /** @var array{emitted: int, unchanged: int, skipped: array<string, int>} */
    private array $totals = ['emitted' => 0, 'unchanged' => 0, 'skipped' => []];

    public function __construct(
        private readonly WooCommerceApiClient $client,
        private readonly WooCommerceApiConnector $connector,
        private readonly WooCommerceApiOrderMapper $mapper,
        private readonly ProviderEventEmitter $emitter,
        private readonly StoreReconciliationRequeue $requeue,
    ) {}

    /**
     * @return array{status: string, emitted: int, unchanged: int, complete: bool, error?: string}
     */
    public function run(Integration $integration, string $mode = self::MODE_DELTA): array
    {
        if (! $this->syncable($integration)) {
            return ['status' => 'skipped', 'emitted' => 0, 'unchanged' => 0, 'complete' => false];
        }

        $lock = Cache::lock('woocommerce-api-sync:'.$integration->id, 900);

        if (! $lock->get()) {
            return ['status' => 'skipped', 'emitted' => 0, 'unchanged' => 0, 'complete' => false];
        }

        try {
            return $this->runLocked($integration, $mode);
        } finally {
            $lock->release();
        }
    }

    public function syncable(Integration $integration): bool
    {
        return $integration->provider === WooCommerceApiConnector::PROVIDER
            && in_array($integration->status, [Integration::STATUS_ACTIVE, Integration::STATUS_DEGRADED], true)
            && ! isset($integration->health['key_rejected_at']);
    }

    /**
     * @return array{status: string, emitted: int, unchanged: int, complete: bool, error?: string}
     */
    private function runLocked(Integration $integration, string $mode): array
    {
        $state = is_array($integration->health['sync'] ?? null) ? $integration->health['sync'] : [];
        $now = Carbon::now()->startOfSecond();
        $this->budget = (int) config('watchdog.woocommerce_api.max_requests_per_run');
        $this->totals = ['emitted' => 0, 'unchanged' => 0, 'skipped' => []];
        $credentials = $this->connector->credentials($integration);

        if ($credentials === null) {
            return $this->fail($integration, new WooCommerceApiRequestFailed('woocommerce_key_rejected', 401));
        }

        $retentionStart = $now->copy()->subDays((int) config('watchdog.woocommerce_api.audit_days'));
        $next = $state;

        try {
            if ($mode === self::MODE_DELTA) {
                $from = isset($state['watermark'])
                    ? Carbon::parse($state['watermark'])->subMinutes((int) config('watchdog.woocommerce_api.delta_overlap_minutes'))->max($retentionStart)
                    : $retentionStart;
                $complete = true;
                $furthest = isset($state['watermark']) ? Carbon::parse($state['watermark']) : null;
                $resumeAt = null;

                foreach (['any', 'trash'] as $status) {
                    $result = $this->walk($integration, $credentials, ['modified_after' => $from->format('Y-m-d\TH:i:s'), 'orderby' => 'modified', 'order' => 'asc', 'status' => $status]);

                    if ($result['complete']) {
                        $furthest = $result['last_modified'] !== null && ($furthest === null || $result['last_modified']->greaterThan($furthest)) ? $result['last_modified'] : $furthest;
                    } else {
                        $complete = false;
                        $reached = $result['last_modified'] ?? $from;
                        $resumeAt = $resumeAt === null ? $reached : $resumeAt->min($reached);
                    }
                }

                $next['watermark'] = ($complete ? ($furthest ?? $now) : $resumeAt)->min($now)->toJSON();
            } else {
                $after = $mode === self::MODE_RECENT ? $now->copy()->subHours((int) config('watchdog.woocommerce_api.recent_hours')) : $retentionStart;
                $complete = true;

                foreach (['any', 'trash'] as $status) {
                    $complete = $this->walk($integration, $credentials, ['after' => $after->format('Y-m-d\TH:i:s'), 'orderby' => 'date', 'order' => 'asc', 'status' => $status])['complete'] && $complete;
                }

                if ($mode === self::MODE_AUDIT && $complete) {
                    $next['deletion_cursor'] = $this->findDeleted($integration, $credentials, $retentionStart, $state['deletion_cursor'] ?? null);
                    $complete = $next['deletion_cursor'] === null;
                }

                $next[$mode === self::MODE_RECENT ? 'last_recent_at' : 'last_audit_at'] = $now->toJSON();
            }
        } catch (WooCommerceApiRequestFailed $failure) {
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
     * Pages through one order listing and maps every order.
     *
     * @param  array{key: string, secret: string}  $credentials
     * @param  array<string, string>  $filter
     * @return array{complete: bool, last_modified: ?Carbon}
     */
    private function walk(Integration $integration, array $credentials, array $filter): array
    {
        $lastModified = null;
        $page = 1;

        do {
            if (! $this->spend()) {
                return ['complete' => false, 'last_modified' => $lastModified];
            }

            $result = $this->client->get($this->baseUrl($integration), $credentials['key'], $credentials['secret'], '/wc/v3/orders', [
                ...$filter,
                'dates_are_gmt' => 'true',
                'per_page' => (int) config('watchdog.woocommerce_api.page_size'),
                'page' => $page,
                'dp' => 6,
                '_fields' => WooCommerceApiOrderMapper::ORDER_FIELDS,
            ]);
            $orders = array_values(array_filter($result['json'], 'is_array'));

            foreach ($orders as $order) {
                if (! $this->ingest($integration, $credentials, $order)) {
                    return ['complete' => false, 'last_modified' => $lastModified];
                }

                if (is_string($order['date_modified_gmt'] ?? null)) {
                    $lastModified = Carbon::parse($order['date_modified_gmt'].'Z');
                }
            }

            $page++;
        } while ($orders !== [] && $page <= (int) ($result['total_pages'] ?? 1));

        return ['complete' => true, 'last_modified' => $lastModified];
    }

    /**
     * @param  array{key: string, secret: string}  $credentials
     * @param  array<string, mixed>  $order
     */
    private function ingest(Integration $integration, array $credentials, array $order): bool
    {
        $orderId = isset($order['id']) && is_scalar($order['id']) ? (string) $order['id'] : '';
        /** @var Order|null $known */
        $known = $orderId === '' ? null : Order::query()
            ->where('tenant_id', $integration->tenant_id)
            ->where('integration_id', $integration->id)
            ->where('external_id', $orderId)
            ->first();
        $mapped = $this->mapper->order($order);

        if ($mapped['draft'] === null) {
            $this->skip((string) $mapped['skipped']);

            return true;
        }

        $drafts = [$mapped['draft']];
        $summary = $this->mapper->refundSummary($order);
        $summaryHash = hash('sha256', json_encode($summary, JSON_THROW_ON_ERROR));
        $knownSummary = DB::table('provider_object_states')
            ->where('integration_id', $integration->id)
            ->where('object_type', 'refund_summary')
            ->where('object_id', $orderId)
            ->value('content_hash');

        if ($knownSummary !== $summaryHash) {
            if ($summary !== []) {
                if (! $this->spend()) {
                    return false;
                }

                $refunds = $this->client->get($this->baseUrl($integration), $credentials['key'], $credentials['secret'], '/wc/v3/orders/'.$orderId.'/refunds', [
                    'per_page' => 100,
                    'dp' => 6,
                    '_fields' => WooCommerceApiOrderMapper::REFUND_FIELDS,
                ])['json'];
                $seen = [];

                foreach (array_filter($refunds, 'is_array') as $refund) {
                    $mappedRefund = $this->mapper->refund($refund, $orderId, $mapped['draft']['data']['currency']);

                    if ($mappedRefund['draft'] === null) {
                        $this->skip((string) $mappedRefund['skipped']);

                        continue;
                    }

                    $seen[$mappedRefund['draft']['aggregate_id']] = true;
                    $drafts[] = $mappedRefund['draft'];
                }
            } else {
                $seen = [];
            }

            if ($known !== null) {
                Refund::query()
                    ->where('tenant_id', $integration->tenant_id)
                    ->where('integration_id', $integration->id)
                    ->where('order_id', $known->id)
                    ->where('status', '!=', 'deleted')
                    ->get()
                    ->reject(fn (Refund $refund): bool => isset($seen[$refund->external_id]))
                    ->each(function (Refund $refund) use (&$drafts, $orderId): void {
                        $drafts[] = $this->mapper->deletedRefund((string) $refund->external_id, [
                            'order_id' => $orderId,
                            'currency' => $refund->currency,
                            'currency_exponent' => (int) $refund->currency_exponent,
                            'amount_minor' => (string) $refund->amount_minor,
                            'external_required' => $refund->external_required,
                            'provider_ref' => $refund->provider_ref,
                        ]);
                    });
            }
        }

        $summaryResult = $this->emitter->emit($integration, $drafts);
        $this->totals['emitted'] += $summaryResult['emitted'];
        $this->totals['unchanged'] += $summaryResult['unchanged'];

        if ($knownSummary !== $summaryHash) {
            DB::table('provider_object_states')->upsert([[
                'tenant_id' => $integration->tenant_id,
                'store_id' => $integration->store_id,
                'integration_id' => $integration->id,
                'object_type' => 'refund_summary',
                'object_id' => $orderId,
                'content_hash' => $summaryHash,
                'revision' => 0,
                'observed_at' => Carbon::now(),
            ]], ['integration_id', 'object_type', 'object_id'], ['content_hash', 'observed_at']);
        }

        return true;
    }

    /**
     * Known orders of the retention window that WooCommerce no longer returns are deleted. They are asked for by id
     * (`include=`, 100 at a time, in every status including trash), so an order missing from a listing only because
     * it changed while the listing was paged is not mistaken for a deleted one. Returns the cursor to continue from.
     *
     * @param  array{key: string, secret: string}  $credentials
     */
    private function findDeleted(Integration $integration, array $credentials, Carbon $retentionStart, ?string $cursor): ?string
    {
        while (true) {
            $ids = Order::query()
                ->where('tenant_id', $integration->tenant_id)
                ->where('integration_id', $integration->id)
                ->whereNull('deleted_at')
                ->where('source_created_at', '>=', $retentionStart)
                ->when($cursor !== null, fn ($query) => $query->whereRaw('CAST(external_id AS BIGINT) > ?', [(int) $cursor]))
                ->orderByRaw('CAST(external_id AS BIGINT)')
                ->limit(100)
                ->pluck('external_id')
                ->map(fn (mixed $id): string => (string) $id)
                ->all();

            if ($ids === []) {
                return null;
            }

            $present = [];

            foreach (['any', 'trash'] as $status) {
                if (! $this->spend()) {
                    return $cursor;
                }

                $result = $this->client->get($this->baseUrl($integration), $credentials['key'], $credentials['secret'], '/wc/v3/orders', [
                    'include' => implode(',', $ids),
                    'status' => $status,
                    'per_page' => 100,
                    '_fields' => 'id',
                ]);

                foreach (array_filter($result['json'], 'is_array') as $row) {
                    $present[(string) ($row['id'] ?? '')] = true;
                }
            }

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
        $pause = (int) config('watchdog.woocommerce_api.request_pause_ms');

        if ($pause > 0) {
            usleep($pause * 1000);
        }

        return true;
    }

    private function skip(string $reason): void
    {
        $this->totals['skipped'][$reason] = ($this->totals['skipped'][$reason] ?? 0) + 1;
    }

    private function baseUrl(Integration $integration): string
    {
        return (string) $integration->store()->value('base_url');
    }

    /**
     * A rejected key stops all calls until the owner reconnects (research D7); other failures stay in health and are
     * retried on the next run.
     *
     * @return array{status: string, emitted: int, unchanged: int, complete: bool, error: string}
     */
    private function fail(Integration $integration, WooCommerceApiRequestFailed $failure): array
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
