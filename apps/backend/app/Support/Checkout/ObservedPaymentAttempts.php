<?php

namespace App\Support\Checkout;

use App\Models\Integration;
use App\Models\ObservedPaymentAttempt;
use App\Models\PaymentAttemptWindow;
use App\Support\Billing\StoreCapacity;
use App\Support\Platform\MaintenanceWindow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Payment attempts the service observes itself (ADR 0056): one row per attempt, its outcome counted in the 5-minute
 * window of the time the outcome became known, windows recomputed from the rows so repeated reads change nothing.
 * The windows feed the same rule as the plugin's aggregates (ADR 0007).
 */
class ObservedPaymentAttempts
{
    public const WINDOW_SECONDS = 300;

    public const STUCK_AFTER_MINUTES = 30;

    public function __construct(
        private readonly PaymentAttemptMonitor $monitor,
    ) {}

    /**
     * @param  list<array{transaction_ref: string, order_ref: string, payment_method: string, attempted_at: Carbon, outcome: string, failure_class: ?string, outcome_at: ?Carbon}>  $observations
     */
    public function record(Integration $integration, array $observations): void
    {
        if ($observations === []) {
            return;
        }

        $this->locked($integration->store_id, function () use ($integration, $observations): void {
            $touched = [];

            DB::transaction(function () use ($integration, $observations, &$touched): void {
                $now = Carbon::now();

                foreach ($observations as $observation) {
                    [$outcome, $outcomeAt, $class] = $this->resolve($observation, $now);
                    /** @var ObservedPaymentAttempt|null $row */
                    $row = ObservedPaymentAttempt::query()
                        ->where('store_id', $integration->store_id)
                        ->where('transaction_ref', $observation['transaction_ref'])
                        ->lockForUpdate()
                        ->first();

                    if ($row !== null && $row->outcome === $outcome && $row->failure_class === $class && $row->payment_method === $observation['payment_method'] && $this->same($row->outcome_at, $outcomeAt)) {
                        continue;
                    }

                    if ($row !== null) {
                        $touched[] = [$row->payment_method, $row->outcome_at];
                    }

                    $attributes = [
                        'payment_method' => $observation['payment_method'],
                        'outcome' => $outcome,
                        'failure_class' => $class,
                        'outcome_at' => $outcomeAt,
                        'updated_at' => $now,
                    ];

                    if ($row === null) {
                        ObservedPaymentAttempt::query()->create([...$attributes,
                            'tenant_id' => $integration->tenant_id,
                            'store_id' => $integration->store_id,
                            'integration_id' => $integration->id,
                            'transaction_ref' => $observation['transaction_ref'],
                            'order_ref' => $observation['order_ref'],
                            'attempted_at' => $observation['attempted_at'],
                            'created_at' => $now,
                        ]);
                    } else {
                        $row->forceFill($attributes)->save();
                    }

                    $touched[] = [$observation['payment_method'], $outcomeAt];
                }
            });

            $this->refresh($integration->tenant_id, $integration->store_id, $integration->id, $touched);
        });
    }

    /**
     * Attempts without a result 30 minutes after they were made become stuck (a failure of the streak).
     */
    public function markStuck(): int
    {
        if (app(MaintenanceWindow::class)->active()) {
            return 0;
        }

        $count = 0;
        $threshold = Carbon::now()->subMinutes(self::STUCK_AFTER_MINUTES);

        ObservedPaymentAttempt::query()
            ->where('outcome', ObservedPaymentAttempt::OUTCOME_PENDING)
            ->where('attempted_at', '<=', $threshold)
            ->whereNotIn('store_id', StoreCapacity::pausedStoreIds())
            ->select(['store_id', 'tenant_id', 'integration_id'])
            ->distinct()
            ->get()
            ->each(function (ObservedPaymentAttempt $store) use (&$count, $threshold): void {
                $this->locked($store->store_id, function () use ($store, &$count, $threshold): void {
                    $touched = [];
                    $stuck = ObservedPaymentAttempt::query()
                        ->where('store_id', $store->store_id)
                        ->where('outcome', ObservedPaymentAttempt::OUTCOME_PENDING)
                        ->where('attempted_at', '<=', $threshold)
                        ->get();

                    foreach ($stuck as $attempt) {
                        $at = $attempt->attempted_at->copy()->addMinutes(self::STUCK_AFTER_MINUTES);
                        $attempt->forceFill(['outcome' => ObservedPaymentAttempt::OUTCOME_PENDING_STUCK, 'failure_class' => 'no_result', 'outcome_at' => $at, 'updated_at' => Carbon::now()])->save();
                        $touched[] = [$attempt->payment_method, $at];
                        $count++;
                    }

                    $this->refresh($store->tenant_id, $store->store_id, $store->integration_id, $touched);
                });
            });

        return $count;
    }

    /**
     * @param  array{attempted_at: Carbon, outcome: string, failure_class: ?string, outcome_at: ?Carbon}  $observation
     * @return array{0: string, 1: ?Carbon, 2: ?string}
     */
    private function resolve(array $observation, Carbon $now): array
    {
        $stuckAt = $observation['attempted_at']->copy()->addMinutes(self::STUCK_AFTER_MINUTES);

        return match ($observation['outcome']) {
            ObservedPaymentAttempt::OUTCOME_PENDING => $stuckAt->lessThanOrEqualTo($now)
                ? [ObservedPaymentAttempt::OUTCOME_PENDING_STUCK, $stuckAt, 'no_result']
                : [ObservedPaymentAttempt::OUTCOME_PENDING, null, null],
            ObservedPaymentAttempt::OUTCOME_PAID => ($observation['outcome_at'] ?? $now)->greaterThan($stuckAt)
                ? [ObservedPaymentAttempt::OUTCOME_LATE_SUCCESS, $observation['outcome_at'] ?? $now, null]
                : [ObservedPaymentAttempt::OUTCOME_PAID, $observation['outcome_at'] ?? $now, null],
            default => [$observation['outcome'], $observation['outcome_at'] ?? $now, $observation['failure_class']],
        };
    }

    /**
     * @param  list<array{0: string, 1: ?Carbon}>  $touched
     */
    private function refresh(string $tenantId, string $storeId, string $integrationId, array $touched): void
    {
        $windows = [];

        foreach ($touched as [$method, $at]) {
            if ($at !== null) {
                $start = $this->windowStart($at);
                $windows[$method.'|'.$start->getTimestamp()] = [$method, $start];
            }
        }

        foreach ($windows as [$method, $start]) {
            $this->recompute($tenantId, $storeId, $integrationId, $method, $start);
        }

        foreach (array_unique(array_column($windows, 0)) as $method) {
            $this->monitor->evaluate($tenantId, $storeId, $method);
        }
    }

    private function recompute(string $tenantId, string $storeId, string $integrationId, string $method, Carbon $start): void
    {
        $end = $start->copy()->addSeconds(self::WINDOW_SECONDS);
        $attempts = ObservedPaymentAttempt::query()
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where('payment_method', $method)
            ->where('outcome_at', '>=', $start)
            ->where('outcome_at', '<', $end)
            ->where('outcome', '!=', ObservedPaymentAttempt::OUTCOME_PENDING)
            ->orderBy('outcome_at')
            ->orderBy('transaction_ref')
            ->get();
        /** @var PaymentAttemptWindow|null $window */
        $window = PaymentAttemptWindow::query()
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where('payment_method', $method)
            ->where('window_start', $start)
            ->first();

        if ($attempts->isEmpty()) {
            $window?->delete();

            return;
        }

        $counters = array_fill_keys(PaymentAttemptWindow::OUTCOME_COUNTERS, 0);
        $classes = [];
        $trailing = 0;

        foreach ($attempts as $attempt) {
            $counters[$attempt->outcome]++;

            if (in_array($attempt->outcome, ObservedPaymentAttempt::FAILURES, true)) {
                $trailing++;
                $classes[(string) $attempt->failure_class] = ($classes[(string) $attempt->failure_class] ?? 0) + 1;
            } else {
                $trailing = 0;
            }
        }

        ksort($classes);
        $data = [...$counters, 'trailing_failures' => $trailing, 'failure_classes' => $classes];
        $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));

        if ($window !== null && $window->payload_hash === $hash) {
            return;
        }

        $now = Carbon::now();
        $attributes = [...$data, 'integration_id' => $integrationId, 'event_id' => null, 'window_end' => $end, 'payload_hash' => $hash, 'updated_at' => $now];

        if ($window === null) {
            PaymentAttemptWindow::query()->create([...$attributes,
                'tenant_id' => $tenantId,
                'store_id' => $storeId,
                'payment_method' => $method,
                'window_start' => $start,
                'source_revision' => 1,
                'created_at' => $now,
            ]);
        } else {
            $window->forceFill([...$attributes, 'source_revision' => $window->source_revision + 1])->save();
        }
    }

    private function windowStart(Carbon $at): Carbon
    {
        $seconds = $at->copy()->utc()->getTimestamp();

        return Carbon::createFromTimestampUTC($seconds - $seconds % self::WINDOW_SECONDS);
    }

    private function same(?Carbon $a, ?Carbon $b): bool
    {
        return $a === null ? $b === null : ($b !== null && $a->getTimestamp() === $b->getTimestamp());
    }

    private function locked(string $storeId, callable $callback): void
    {
        Cache::lock('observed-payment-attempts:'.$storeId, 60)->block(15, $callback);
    }
}
