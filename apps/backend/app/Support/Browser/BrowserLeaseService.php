<?php

namespace App\Support\Browser;

use App\Models\BrowserWorker;
use App\Models\CheckAttempt;
use App\Models\CheckRun;
use App\Models\CheckScenario;
use App\Models\CheckStep;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class BrowserLeaseService
{
    public const ERROR_LEASE_NOT_FOUND = 'lease_not_found';

    public const ERROR_LEASE_TOKEN_INVALID = 'lease_token_invalid';

    public const ERROR_STALE_FENCING_TOKEN = 'stale_fencing_token';

    public const ERROR_LEASE_EXPIRED = 'lease_expired';

    public const ERROR_RESULT_CONFLICT = 'result_conflict';

    public const ERROR_INFRA_TIMEOUT = 'infra_timeout';

    public const ERROR_RUN_CANCELLED = 'run_cancelled';

    public function __construct(
        private readonly CheckScheduler $scheduler,
        private readonly CheckOutcomePolicy $policy,
        private readonly CheckOutcomeEvaluator $evaluator,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function lease(BrowserWorker $worker, string $browserVersion, string $location): ?array
    {
        $this->recoverExpired();

        for ($tries = 0; $tries < 5; $tries++) {
            $lease = DB::transaction(function () use ($worker, $browserVersion, $location): array|false|null {
                $now = Carbon::now();
                $query = CheckRun::query()
                    ->where('status', CheckRun::STATUS_QUEUED)
                    ->where('next_attempt_at', '<=', $now)
                    ->orderBy('next_attempt_at')
                    ->orderBy('id');
                /** @var CheckRun|null $run */
                $run = (DB::getDriverName() === 'pgsql' ? $query->lock('for update skip locked') : $query->lockForUpdate())->first();

                if ($run === null) {
                    return null;
                }

                /** @var CheckScenario|null $scenario */
                $scenario = CheckScenario::query()->whereKey($run->scenario_id)->first();

                if ($scenario === null || ! $scenario->enabled || $this->scheduler->eligibleStore($scenario) === null) {
                    $run->forceFill(['status' => CheckRun::STATUS_CANCELLED, 'finished_at' => $now, 'error_code' => self::ERROR_RUN_CANCELLED])->save();

                    return false;
                }

                $token = bin2hex(random_bytes(32));
                $fencing = $run->last_fencing_token + 1;
                $deadline = $now->copy()->addSeconds((int) config('watchdog.browser.absolute_run_seconds'));
                $attempt = CheckAttempt::query()->create([
                    'tenant_id' => $run->tenant_id,
                    'store_id' => $run->store_id,
                    'run_id' => $run->id,
                    'attempt_number' => CheckAttempt::query()->where('run_id', $run->id)->count() + 1,
                    'worker_id' => $worker->id,
                    'fencing_token' => $fencing,
                    'lease_token_hash' => hash('sha256', $token),
                    'lease_until' => $this->leaseUntil($now, $deadline),
                    'absolute_deadline_at' => $deadline,
                    'status' => CheckAttempt::STATUS_RUNNING,
                    'browser_version' => $browserVersion,
                    'location' => $location,
                    'started_at' => $now,
                ]);

                $run->forceFill([
                    'status' => CheckRun::STATUS_RUNNING,
                    'started_at' => $run->started_at ?? $now,
                    'last_fencing_token' => $fencing,
                ])->save();

                $snapshot = $run->config_snapshot;
                $networkPolicy = $snapshot['network_policy'];
                unset($snapshot['network_policy']);

                return [
                    'attempt_id' => $attempt->id,
                    'run_id' => $run->id,
                    'store_id' => $run->store_id,
                    'fencing_token' => $fencing,
                    'lease_token' => $token,
                    'lease_until' => $attempt->lease_until->toJSON(),
                    'absolute_deadline_at' => $deadline->toJSON(),
                    'scenario' => $snapshot,
                    'network_policy' => $networkPolicy,
                ];
            });

            if ($lease !== false) {
                $worker->forceFill(['last_seen_at' => Carbon::now()])->save();

                return $lease;
            }
        }

        return null;
    }

    /**
     * @return array{lease_until: string, absolute_deadline_at: string}
     */
    public function heartbeat(BrowserWorker $worker, string $attemptId, string $leaseToken, int $fencingToken): array
    {
        return DB::transaction(function () use ($worker, $attemptId, $leaseToken, $fencingToken): array {
            $attempt = $this->lockedAttempt($worker, $attemptId, $leaseToken, $fencingToken);
            $now = Carbon::now();

            if ($attempt->status !== CheckAttempt::STATUS_RUNNING || $attempt->lease_until->lessThan($now)) {
                throw new LeaseConflict(self::ERROR_LEASE_EXPIRED);
            }

            $attempt->forceFill([
                'last_heartbeat_at' => $now,
                'lease_until' => $this->leaseUntil($now, $attempt->absolute_deadline_at),
            ])->save();
            $worker->forceFill(['last_seen_at' => $now])->save();

            return [
                'lease_until' => $attempt->lease_until->toJSON(),
                'absolute_deadline_at' => $attempt->absolute_deadline_at->toJSON(),
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function complete(BrowserWorker $worker, string $attemptId, string $leaseToken, array $result): void
    {
        $resultHash = hash('sha256', json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        DB::transaction(function () use ($worker, $attemptId, $leaseToken, $result, $resultHash): void {
            $attempt = $this->lockedAttempt($worker, $attemptId, $leaseToken, (int) $result['fencing_token']);

            if ($attempt->result_hash !== null) {
                if (! hash_equals($attempt->result_hash, $resultHash)) {
                    throw new LeaseConflict(self::ERROR_RESULT_CONFLICT);
                }

                return;
            }

            $now = Carbon::now();

            if ($attempt->status !== CheckAttempt::STATUS_RUNNING || $attempt->lease_until->lessThan($now)) {
                throw new LeaseConflict(self::ERROR_LEASE_EXPIRED);
            }

            /** @var CheckRun $run */
            $run = CheckRun::query()->whereKey($attempt->run_id)->lockForUpdate()->firstOrFail();

            if ($run->last_fencing_token !== $attempt->fencing_token || $run->status !== CheckRun::STATUS_RUNNING) {
                throw new LeaseConflict(self::ERROR_STALE_FENCING_TOKEN);
            }

            foreach ($result['steps'] as $step) {
                CheckStep::query()->create([
                    'tenant_id' => $attempt->tenant_id,
                    'store_id' => $attempt->store_id,
                    'attempt_id' => $attempt->id,
                    'step_index' => $step['index'],
                    'step_code' => $step['code'],
                    'status' => $step['status'],
                    'started_at' => Carbon::parse($step['started_at']),
                    'finished_at' => Carbon::parse($step['finished_at']),
                    'assertions' => $step['assertions'],
                    'network_summary' => $step['network_summary'] ?? [],
                    'error_code' => $step['error_code'] ?? null,
                ]);
            }

            $attempt->forceFill([
                'status' => $result['status'],
                'finished_at' => $now,
                'result_hash' => $resultHash,
                'error_code' => $result['error_code'] ?? null,
                'sanitized_error' => $result['diagnostics'],
            ])->save();

            $decision = $this->policy->decide($run, $attempt);

            if ($decision['final']) {
                $run->forceFill([
                    'status' => $decision['run_status'],
                    'finished_at' => $now,
                    'error_code' => $decision['error_code'],
                ])->save();
                $this->evaluator->runFinished($run, $attempt);
            } else {
                $run->forceFill([
                    'status' => CheckRun::STATUS_QUEUED,
                    'next_attempt_at' => $now->copy()->addSeconds((int) config('watchdog.browser.retry_delay_seconds')),
                ])->save();
            }
        });
    }

    public function recoverExpired(int $limit = 50): int
    {
        $now = Carbon::now();
        $expiredIds = CheckAttempt::query()
            ->where('status', CheckAttempt::STATUS_RUNNING)
            ->where('lease_until', '<', $now)
            ->orderBy('lease_until')
            ->limit($limit)
            ->pluck('id');

        foreach ($expiredIds as $attemptId) {
            DB::transaction(function () use ($attemptId): void {
                /** @var CheckAttempt|null $attempt */
                $attempt = CheckAttempt::query()->whereKey($attemptId)->lockForUpdate()->first();
                $now = Carbon::now();

                if ($attempt === null || $attempt->status !== CheckAttempt::STATUS_RUNNING || $attempt->lease_until->greaterThanOrEqualTo($now)) {
                    return;
                }

                $attempt->forceFill(['status' => CheckAttempt::STATUS_EXPIRED, 'finished_at' => $now, 'error_code' => self::ERROR_LEASE_EXPIRED])->save();

                /** @var CheckRun $run */
                $run = CheckRun::query()->whereKey($attempt->run_id)->lockForUpdate()->firstOrFail();

                if ($run->status !== CheckRun::STATUS_RUNNING || $run->last_fencing_token !== $attempt->fencing_token) {
                    return;
                }

                if (CheckAttempt::query()->where('run_id', $run->id)->count() >= (int) config('watchdog.browser.max_attempts')) {
                    $run->forceFill(['status' => CheckRun::STATUS_INCONCLUSIVE, 'finished_at' => $now, 'error_code' => self::ERROR_INFRA_TIMEOUT])->save();
                    $this->evaluator->runFinished($run, $attempt);
                } else {
                    $run->forceFill(['status' => CheckRun::STATUS_QUEUED, 'next_attempt_at' => $now])->save();
                }
            });
        }

        return $expiredIds->count();
    }

    private function lockedAttempt(BrowserWorker $worker, string $attemptId, string $leaseToken, int $fencingToken): CheckAttempt
    {
        /** @var CheckAttempt|null $attempt */
        $attempt = CheckAttempt::query()->whereKey($attemptId)->where('worker_id', $worker->id)->lockForUpdate()->first();

        if ($attempt === null) {
            throw new LeaseConflict(self::ERROR_LEASE_NOT_FOUND, 404);
        }

        if (! hash_equals($attempt->lease_token_hash, hash('sha256', $leaseToken))) {
            throw new LeaseConflict(self::ERROR_LEASE_TOKEN_INVALID);
        }

        if ($attempt->fencing_token !== $fencingToken) {
            throw new LeaseConflict(self::ERROR_STALE_FENCING_TOKEN);
        }

        return $attempt;
    }

    private function leaseUntil(Carbon $now, Carbon $absoluteDeadline): Carbon
    {
        $lease = $now->copy()->addSeconds((int) config('watchdog.browser.lease_seconds'));
        $cap = $absoluteDeadline->copy()->addSeconds((int) config('watchdog.browser.result_grace_seconds'));

        return $lease->lessThan($cap) ? $lease : $cap;
    }
}
