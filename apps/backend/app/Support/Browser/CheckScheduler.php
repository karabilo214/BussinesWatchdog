<?php

namespace App\Support\Browser;

use App\Models\CheckRun;
use App\Models\CheckScenario;
use App\Models\Store;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CheckScheduler
{
    public const ERROR_RUN_ACTIVE = 'check_run_already_active';

    public const ERROR_RATE_LIMITED = 'manual_check_rate_limited';

    public const ERROR_NOT_ELIGIBLE = 'store_not_eligible_for_browser_checks';

    public function __construct(
        private readonly ScenarioDefinition $definition,
    ) {}

    /**
     * @return array{due: int, created: int}
     */
    public function scheduleDue(int $limit = 200): array
    {
        $now = Carbon::now();
        $due = CheckScenario::query()
            ->where('enabled', true)
            ->where(fn ($query) => $query->whereNull('next_due_at')->orWhere('next_due_at', '<=', $now))
            ->orderBy('next_due_at')
            ->limit($limit)
            ->pluck('id');
        $created = 0;

        foreach ($due as $scenarioId) {
            try {
                if ($this->scheduleScenario($scenarioId)) {
                    $created++;
                }
            } catch (QueryException $exception) {
                report($exception);
            }
        }

        return ['due' => $due->count(), 'created' => $created];
    }

    public function runNow(CheckScenario $scenario): CheckRun|string
    {
        return DB::transaction(function () use ($scenario): CheckRun|string {
            /** @var CheckScenario $locked */
            $locked = CheckScenario::query()->whereKey($scenario->id)->lockForUpdate()->firstOrFail();
            $store = $this->eligibleStore($locked);

            if ($store === null || ! $locked->enabled) {
                return self::ERROR_NOT_ELIGIBLE;
            }

            $recentManual = CheckRun::query()
                ->where('store_id', $locked->store_id)
                ->where('trigger', CheckRun::TRIGGER_MANUAL)
                ->where('created_at', '>', Carbon::now()->subSeconds((int) (60 / max(1, (int) config('watchdog.browser.manual_runs_per_minute')))))
                ->exists();

            if ($recentManual) {
                return self::ERROR_RATE_LIMITED;
            }

            if ($this->hasActiveRun($locked->store_id)) {
                return self::ERROR_RUN_ACTIVE;
            }

            return $this->createRun($locked, $store, CheckRun::TRIGGER_MANUAL, 'manual:'.Str::uuid7());
        });
    }

    private function scheduleScenario(string $scenarioId): bool
    {
        return DB::transaction(function () use ($scenarioId): bool {
            /** @var CheckScenario|null $scenario */
            $scenario = CheckScenario::query()->whereKey($scenarioId)->lockForUpdate()->first();
            $now = Carbon::now();

            if ($scenario === null || ! $scenario->enabled || ($scenario->next_due_at !== null && $scenario->next_due_at->greaterThan($now))) {
                return false;
            }

            $store = $this->eligibleStore($scenario);

            if ($store === null) {
                $scenario->forceFill(['next_due_at' => $this->nextDue($scenario, $now)])->save();

                return false;
            }

            if ($this->hasActiveRun($scenario->store_id)) {
                return false;
            }

            $slot = ($scenario->next_due_at ?? $now)->copy()->utc()->format('Ymd\THis');
            $this->createRun($scenario, $store, CheckRun::TRIGGER_SCHEDULED, 'scheduled:'.$scenario->id.':'.$slot);
            $scenario->forceFill(['next_due_at' => $this->nextDue($scenario, $now)])->save();

            return true;
        });
    }

    private function createRun(CheckScenario $scenario, Store $store, string $trigger, string $dedupeKey): CheckRun
    {
        $now = Carbon::now();

        return CheckRun::query()->create([
            'tenant_id' => $scenario->tenant_id,
            'store_id' => $scenario->store_id,
            'scenario_id' => $scenario->id,
            'scenario_version' => $scenario->version,
            'trigger' => $trigger,
            'dedupe_key' => $dedupeKey,
            'status' => CheckRun::STATUS_QUEUED,
            'config_snapshot' => $this->definition->snapshot($scenario, $store),
            'scheduled_at' => $now,
            'last_fencing_token' => 0,
            'next_attempt_at' => $now,
            'created_at' => $now,
        ]);
    }

    public function eligibleStore(CheckScenario $scenario): ?Store
    {
        /** @var Store|null $store */
        $store = Store::query()
            ->where('tenant_id', $scenario->tenant_id)
            ->whereKey($scenario->store_id)
            ->where('status', 'active')
            ->where('browser_enabled', true)
            ->whereNotNull('verified_at')
            ->whereIn('tenant_id', Tenant::query()->where('status', 'active')->select('id'))
            ->first();

        return $store;
    }

    private function hasActiveRun(string $storeId): bool
    {
        return CheckRun::query()->where('store_id', $storeId)->whereIn('status', CheckRun::ACTIVE_STATUSES)->exists();
    }

    private function nextDue(CheckScenario $scenario, Carbon $now): Carbon
    {
        $jitter = (float) config('watchdog.browser.schedule_jitter');
        $factor = 1 + (mt_rand(-1000, 1000) / 1000) * $jitter;

        return $now->copy()->addSeconds((int) round($scenario->interval_seconds * $factor));
    }
}
