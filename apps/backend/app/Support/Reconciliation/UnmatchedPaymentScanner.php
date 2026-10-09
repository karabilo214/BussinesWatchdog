<?php

namespace App\Support\Reconciliation;

use App\Models\FinancialTransaction;
use App\Models\Integration;
use App\Models\PaymentAllocation;
use App\Models\ReconciliationFinding;
use App\Models\ReconciliationRun;
use App\Models\Store;
use App\Support\Integrations\ConnectorFreshness;
use App\Support\Integrations\ProviderCoverage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class UnmatchedPaymentScanner
{
    public const ALGORITHM_VERSION = 'v1';

    public const CONFIG_VERSION = 1;

    public const ORPHAN_GRACE_HOURS = 24;

    public const MAX_FINDINGS_PER_RUN = 100;

    public function __construct(
        private readonly ProviderCoverage $coverage,
        private readonly ConnectorFreshness $freshness,
    ) {}

    public function scan(Store $store, string $trigger = 'manual'): ReconciliationRun
    {
        return DB::transaction(function () use ($store, $trigger): ReconciliationRun {
            $now = Carbon::now();
            $providerConnected = $this->coverage->isConnected($store->tenant_id, $store->id);
            $storeDataStale = $this->freshness->storeDataStale($store->tenant_id, $store->id);

            $run = ReconciliationRun::query()->create([
                'tenant_id' => $store->tenant_id,
                'store_id' => $store->id,
                'status' => ReconciliationRun::STATUS_RUNNING,
                'algorithm_version' => self::ALGORITHM_VERSION,
                'config_version' => self::CONFIG_VERSION,
                'currency' => null,
                'scope' => ['store_id' => $store->id, 'trigger' => $trigger, 'rule_code' => ReconciliationFinding::RULE_PAYMENT_WITHOUT_ORDER],
                'coverage_snapshot' => ['provider_connected' => $providerConnected, 'store_data_stale' => $storeDataStale],
                'counters' => [],
                'started_at' => $now,
                'created_at' => $now,
            ]);

            $graceDeadline = $now->copy()->subHours(self::ORPHAN_GRACE_HOURS);

            $allocatedCaptureIds = PaymentAllocation::query()
                ->where('store_id', $store->id)
                ->whereNull('revoked_at')
                ->pluck('capture_transaction_id');

            $orphanCaptures = ! $providerConnected || $storeDataStale ? collect() : FinancialTransaction::query()
                ->where('tenant_id', $store->tenant_id)
                ->where('store_id', $store->id)
                ->where('source_authority', Integration::SOURCE_INDEPENDENT_PROVIDER)
                ->where('kind', 'capture')
                ->where('status', 'succeeded')
                ->where('occurred_at', '<=', $graceDeadline)
                ->whereNotIn('id', $allocatedCaptureIds)
                ->orderBy('occurred_at')
                ->limit(self::MAX_FINDINGS_PER_RUN)
                ->get();

            foreach ($orphanCaptures as $capture) {
                ReconciliationFinding::query()->create([
                    'tenant_id' => $store->tenant_id,
                    'store_id' => $store->id,
                    'run_id' => $run->id,
                    'order_id' => null,
                    'payment_id' => $capture->payment_id,
                    'rule_code' => ReconciliationFinding::RULE_PAYMENT_WITHOUT_ORDER,
                    'status' => ReconciliationFinding::STATUS_PENDING,
                    'reason_code' => 'unmatched_capture_awaiting_review',
                    'currency' => $capture->currency,
                    'currency_exponent' => $capture->currency_exponent,
                    'actual_minor' => $capture->amount_minor,
                    'evidence' => [
                        'capture_transaction_id' => $capture->id,
                        'occurred_at' => $capture->occurred_at->toJSON(),
                    ],
                    'config_snapshot' => [
                        'algorithm_version' => self::ALGORITHM_VERSION,
                        'config_version' => self::CONFIG_VERSION,
                        'orphan_grace_hours' => self::ORPHAN_GRACE_HOURS,
                    ],
                    'evaluated_at' => $now,
                ]);
            }

            $run->forceFill([
                'status' => ReconciliationRun::STATUS_COMPLETED,
                'finished_at' => Carbon::now(),
                'counters' => ['findings' => $orphanCaptures->count()],
            ])->save();

            return $run->refresh();
        });
    }
}
