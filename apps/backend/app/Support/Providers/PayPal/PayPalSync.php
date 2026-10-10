<?php

namespace App\Support\Providers\PayPal;

use App\Models\Integration;
use App\Models\ReconciliationDirtySubject;
use App\Support\Providers\ProviderEventEmitter;
use App\Support\Reconciliation\StoreReconciliationRequeue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Polling is the guarantee, webhooks only make it faster (spec §10). PayPal has no list endpoint for orders or
 * captures, so polling reads Transaction Search, which lags behind (its `last_refreshed_datetime`), in windows of at
 * most 31 days. Each transaction is resolved to its PayPal order, and the whole order is read and mapped, so
 * captures, refunds and the payment status always come from one consistent object. Delta reads from the watermark
 * minus an overlap; audit walks the retention window once a day and continues hourly when the request budget runs out.
 */
class PayPalSync
{
    public const MODE_DELTA = 'delta';

    public const MODE_AUDIT = 'audit';

    private int $budget = 0;

    public function __construct(
        private readonly PayPalClient $client,
        private readonly PayPalConnector $connector,
        private readonly PayPalOrderMapper $mapper,
        private readonly ProviderEventEmitter $emitter,
        private readonly StoreReconciliationRequeue $requeue,
    ) {}

    /**
     * @return array{status: string, emitted: int, unchanged: int, complete: bool, error?: string}
     */
    public function run(Integration $integration, string $mode = self::MODE_DELTA): array
    {
        if ($integration->provider !== 'paypal' || $integration->status !== Integration::STATUS_ACTIVE) {
            return ['status' => 'skipped', 'emitted' => 0, 'unchanged' => 0, 'complete' => false];
        }

        $state = is_array($integration->health['sync'] ?? null) ? $integration->health['sync'] : [];
        $now = Carbon::now()->startOfSecond();
        $retentionStart = $now->copy()->subDays((int) config('watchdog.paypal.audit_days'));
        $this->budget = (int) config('watchdog.paypal.max_orders_per_run');
        $totals = ['emitted' => 0, 'unchanged' => 0, 'skipped' => []];

        if ($mode === self::MODE_AUDIT && ! isset($state['audit_cursor']) && isset($state['last_audit_at']) && Carbon::parse($state['last_audit_at'])->greaterThan($now->copy()->subHours(23))) {
            return ['status' => 'skipped', 'emitted' => 0, 'unchanged' => 0, 'complete' => true];
        }

        if ($mode === self::MODE_AUDIT) {
            $from = isset($state['audit_cursor']) ? Carbon::parse($state['audit_cursor']) : $retentionStart;
        } else {
            $from = isset($state['watermark']) ? Carbon::parse($state['watermark'])->subMinutes((int) config('watchdog.paypal.delta_overlap_minutes')) : $retentionStart;
        }

        $from = $from->max($retentionStart);

        try {
            $token = $this->token($integration);
            $covered = $from->copy();
            $complete = true;
            $refreshed = $state['search_refreshed_at'] ?? null;

            while ($covered->lessThan($now)) {
                $windowEnd = $covered->copy()->addDays((int) config('watchdog.paypal.search_window_days'))->min($now);
                $window = $this->readWindow($integration, $token, $covered, $windowEnd, $totals);
                $refreshed = $window['refreshed_at'] ?? $refreshed;

                if (! $window['complete']) {
                    $complete = false;

                    break;
                }

                $covered = $windowEnd->copy()->min($window['refreshed_at'] !== null ? Carbon::parse($window['refreshed_at']) : $windowEnd);

                if ($covered->lessThan($windowEnd)) {
                    break;
                }
            }
        } catch (PayPalRequestFailed $failure) {
            return $this->fail($integration, $failure);
        }

        $next = $state;

        if ($mode === self::MODE_AUDIT) {
            $next['audit_cursor'] = $complete && $covered->greaterThanOrEqualTo($now->copy()->subDay()) ? null : $covered->toJSON();
            $next['last_audit_at'] = $next['audit_cursor'] === null ? $now->toJSON() : ($state['last_audit_at'] ?? null);
        } else {
            $next['watermark'] = $covered->max(isset($state['watermark']) ? Carbon::parse($state['watermark']) : $covered)->toJSON();
        }

        $this->updateHealth($integration, [
            'sync' => [
                ...array_filter($next, fn (mixed $value): bool => $value !== null),
                'search_refreshed_at' => $refreshed,
                'last_run_at' => $now->toJSON(),
                'last_mode' => $mode,
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
     * Reads one PayPal order and emits what changed; used by webhooks and by polling.
     *
     * @return array{emitted: int, unchanged: int, skipped: ?string}
     */
    public function syncOrder(Integration $integration, string $orderId): array
    {
        return $this->ingestOrder($integration, $this->token($integration), $orderId);
    }

    /** The PayPal order a capture belongs to (from its `up` link), remembered because captures never move. */
    public function orderOfCapture(Integration $integration, string $captureId): ?string
    {
        return $this->captureOrder($integration, $this->token($integration), $captureId);
    }

    /**
     * @param  array{emitted: int, unchanged: int, skipped: array<string, int>}  $totals
     * @return array{complete: bool, refreshed_at: ?string}
     */
    private function readWindow(Integration $integration, string $token, Carbon $from, Carbon $to, array &$totals): array
    {
        $orders = [];
        $refreshedAt = null;
        $maxPages = (int) config('watchdog.paypal.max_search_pages_per_run');

        for ($page = 1; $page <= $maxPages; $page++) {
            $result = $this->client->get($integration->mode, $token, '/v1/reporting/transactions', [
                'start_date' => $from->format('Y-m-d\TH:i:s\Z'),
                'end_date' => $to->format('Y-m-d\TH:i:s\Z'),
                'fields' => 'transaction_info',
                'page_size' => (int) config('watchdog.paypal.search_page_size'),
                'page' => $page,
            ]);
            $refreshedAt = is_string($result['last_refreshed_datetime'] ?? null) ? Carbon::parse($result['last_refreshed_datetime'])->toJSON() : $refreshedAt;

            foreach (is_array($result['transaction_details'] ?? null) ? $result['transaction_details'] : [] as $row) {
                $captureId = $this->captureIdOf(is_array($row['transaction_info'] ?? null) ? $row['transaction_info'] : []);

                if ($captureId === null) {
                    continue;
                }

                if ($this->budget <= 0) {
                    return ['complete' => false, 'refreshed_at' => $refreshedAt];
                }

                $orderId = $this->captureOrder($integration, $token, $captureId);

                if ($orderId !== null) {
                    $orders[$orderId] = true;
                }
            }

            if ($page >= (int) ($result['total_pages'] ?? 1)) {
                foreach (array_keys($orders) as $orderId) {
                    if ($this->budget <= 0) {
                        return ['complete' => false, 'refreshed_at' => $refreshedAt];
                    }

                    $summary = $this->ingestOrder($integration, $token, (string) $orderId);
                    $totals['emitted'] += $summary['emitted'];
                    $totals['unchanged'] += $summary['unchanged'];

                    if ($summary['skipped'] !== null) {
                        $totals['skipped'][$summary['skipped']] = ($totals['skipped'][$summary['skipped']] ?? 0) + 1;
                    }
                }

                return ['complete' => true, 'refreshed_at' => $refreshedAt];
            }
        }

        return ['complete' => false, 'refreshed_at' => $refreshedAt];
    }

    /**
     * Payments received (event codes T00xx) are captures; refunds and reversals (T11xx) point to the capture they
     * return through `paypal_reference_id`. Other rows (fees, transfers, conversions, holds) are not used.
     *
     * @param  array<string, mixed>  $info
     */
    private function captureIdOf(array $info): ?string
    {
        $code = (string) ($info['transaction_event_code'] ?? '');
        $id = match (true) {
            str_starts_with($code, 'T00') => $info['transaction_id'] ?? null,
            str_starts_with($code, 'T11') => ($info['paypal_reference_id_type'] ?? null) === 'TXN' ? ($info['paypal_reference_id'] ?? null) : null,
            default => null,
        };

        return is_string($id) && preg_match('/^[A-Z0-9]{1,36}$/', $id) === 1 ? $id : null;
    }

    private function captureOrder(Integration $integration, string $token, string $captureId): ?string
    {
        $key = 'paypal:capture-order:'.$integration->id.':'.$captureId;
        $known = Cache::get($key);

        if (is_string($known)) {
            return $known;
        }

        $this->budget--;

        try {
            $capture = $this->client->get($integration->mode, $token, '/v2/payments/captures/'.$captureId);
        } catch (PayPalRequestFailed $failure) {
            if ($failure->reasonCode === 'paypal_not_found') {
                return null;
            }

            throw $failure;
        }

        $orderId = $capture['supplementary_data']['related_ids']['order_id'] ?? null;

        foreach (is_array($capture['links'] ?? null) ? $capture['links'] : [] as $link) {
            if (($link['rel'] ?? null) === 'up' && preg_match('#/v2/checkout/orders/([A-Z0-9]{1,36})$#', (string) ($link['href'] ?? ''), $match) === 1) {
                $orderId = $match[1];
            }
        }

        if (! is_string($orderId) || preg_match('/^[A-Z0-9]{1,36}$/', $orderId) !== 1) {
            return null;
        }

        Cache::put($key, $orderId, Carbon::now()->addDays((int) config('watchdog.paypal.audit_days') + 30));

        return $orderId;
    }

    /**
     * @return array{emitted: int, unchanged: int, skipped: ?string}
     */
    private function ingestOrder(Integration $integration, string $token, string $orderId): array
    {
        $this->budget--;
        $order = $this->client->get($integration->mode, $token, '/v2/checkout/orders/'.$orderId);
        $mapped = $this->mapper->map($integration, $order);

        if ($mapped['skipped'] !== null) {
            return ['emitted' => 0, 'unchanged' => 0, 'skipped' => $mapped['skipped']];
        }

        return [...$this->emitter->emit($integration, $mapped['drafts']), 'skipped' => null];
    }

    private function token(Integration $integration): string
    {
        $credentials = $this->connector->credentials($integration);

        if ($credentials === null) {
            throw new PayPalRequestFailed('paypal_credentials_rejected', 401);
        }

        return $this->client->token($integration->mode, $credentials['client_id'], $credentials['client_secret'])['token'];
    }

    /**
     * Rejected credentials stop all calls (status degraded, money checks fall back to unknown); other failures are
     * kept in health and retried on the next run.
     *
     * @return array{status: string, emitted: int, unchanged: int, complete: bool, error: string}
     */
    private function fail(Integration $integration, PayPalRequestFailed $failure): array
    {
        $error = ['code' => $failure->reasonCode, 'at' => Carbon::now()->toJSON(), 'retry_after_seconds' => $failure->retryAfterSeconds];

        if ($failure->credentialsUnusable() || $failure->reasonCode === 'paypal_permission_missing') {
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
}
