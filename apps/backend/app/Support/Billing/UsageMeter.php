<?php

namespace App\Support\Billing;

use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class UsageMeter
{
    public const ERROR_LIMIT_REACHED = 'usage_limit_reached';

    public const MANUAL_RUNS = FeatureCatalog::MANUAL_RUNS_PER_DAY;

    public const STORE_EVENTS = FeatureCatalog::MONTHLY_EVENT_BUDGET;

    private const PERIODS = [
        self::MANUAL_RUNS => 'day',
        self::STORE_EVENTS => 'month',
    ];

    public function consume(string $tenantId, string $metric, string $operationKey, ?int $limit, string $reasonCode, int $delta = 1, ?Carbon $now = null): bool
    {
        if ($delta <= 0) {
            throw new InvalidArgumentException('usage_delta_invalid');
        }

        $now ??= Carbon::now();

        return DB::transaction(function () use ($tenantId, $metric, $operationKey, $limit, $reasonCode, $delta, $now): bool {
            [$start, $end] = $this->period($tenantId, $metric, $now);
            $bucket = $this->lockedBucket($tenantId, $metric, $start, $end, $now);

            $recorded = DB::table('usage_entries')
                ->where('tenant_id', $tenantId)
                ->where('metric_key', $metric)
                ->where('operation_key', $operationKey)
                ->exists();

            if ($recorded) {
                return true;
            }

            if ($limit !== null && (int) $bucket->value + $delta > $limit) {
                return false;
            }

            DB::table('usage_entries')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'metric_key' => $metric,
                'period_start' => $start,
                'operation_key' => $operationKey,
                'delta' => $delta,
                'reason_code' => $reasonCode,
                'occurred_at' => $now,
            ]);

            DB::table('usage_buckets')
                ->where('tenant_id', $tenantId)
                ->where('metric_key', $metric)
                ->where('period_start', $start)
                ->update(['value' => DB::raw('value + '.$delta), 'updated_at' => $now]);

            return true;
        });
    }

    /**
     * @return array{used: int, period_start: Carbon, period_end: Carbon}
     */
    public function current(string $tenantId, string $metric, ?Carbon $now = null): array
    {
        [$start, $end] = $this->period($tenantId, $metric, $now ?? Carbon::now());
        $value = DB::table('usage_buckets')
            ->where('tenant_id', $tenantId)
            ->where('metric_key', $metric)
            ->where('period_start', $start)
            ->value('value');

        return ['used' => (int) ($value ?? 0), 'period_start' => $start, 'period_end' => $end];
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    public function period(string $tenantId, string $metric, Carbon $now): array
    {
        $unit = self::PERIODS[$metric] ?? throw new InvalidArgumentException('usage_metric_unknown');
        $timezone = Tenant::query()->whereKey($tenantId)->value('timezone') ?: 'UTC';
        $local = $now->copy()->setTimezone($timezone);
        $start = $unit === 'day' ? $local->copy()->startOfDay() : $local->copy()->startOfMonth();
        $end = $unit === 'day' ? $start->copy()->addDay() : $start->copy()->addMonth();

        return [$start->utc(), $end->utc()];
    }

    private function lockedBucket(string $tenantId, string $metric, Carbon $start, Carbon $end, Carbon $now): object
    {
        DB::table('usage_buckets')->insertOrIgnore([
            'tenant_id' => $tenantId,
            'metric_key' => $metric,
            'period_start' => $start,
            'period_end' => $end,
            'value' => 0,
            'updated_at' => $now,
        ]);

        return DB::table('usage_buckets')
            ->where('tenant_id', $tenantId)
            ->where('metric_key', $metric)
            ->where('period_start', $start)
            ->lockForUpdate()
            ->first();
    }
}
