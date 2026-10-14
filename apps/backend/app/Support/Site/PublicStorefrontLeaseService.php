<?php

namespace App\Support\Site;

use App\Models\BrowserWorker;
use App\Models\PublicStorefrontCheck;
use App\Models\PublicStorefrontCheckArtifact;
use App\Support\Browser\LeaseConflict;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class PublicStorefrontLeaseService
{
    public const TYPE = 'public_storefront';

    public const ERROR_LEASE_NOT_FOUND = 'lease_not_found';
    public const ERROR_LEASE_TOKEN_INVALID = 'lease_token_invalid';
    public const ERROR_STALE_FENCING_TOKEN = 'stale_fencing_token';
    public const ERROR_LEASE_EXPIRED = 'lease_expired';
    public const ERROR_RESULT_CONFLICT = 'result_conflict';
    public const ERROR_ARTIFACT_UNKNOWN = 'artifact_unknown';
    public const ERROR_LIMIT_REACHED = 'artifact_limit_reached';
    public const ERROR_TOO_LARGE = 'artifact_too_large';
    public const ERROR_TYPE_INVALID = 'artifact_type_invalid';
    public const ERROR_CHECKSUM_MISMATCH = 'artifact_checksum_mismatch';
    public const ERROR_STORAGE_UNAVAILABLE = 'artifact_storage_unavailable';

    private const SIGNATURES = [
        'image/jpeg' => ["\xFF\xD8\xFF", 'jpg'],
        'image/png' => ["\x89PNG\r\n\x1A\n", 'png'],
    ];

    /**
     * @return array<string, mixed>|null
     */
    public function lease(BrowserWorker $worker, string $browserVersion, string $location): ?array
    {
        $this->recoverExpired();

        return DB::transaction(function () use ($worker, $browserVersion, $location): ?array {
            $now = Carbon::now();
            $maxRunning = max(0, (int) config('watchdog.public_check.max_running', 1));

            if ($maxRunning === 0 || PublicStorefrontCheck::query()->where('status', PublicStorefrontCheck::STATUS_RUNNING)->count() >= $maxRunning) {
                return null;
            }

            $query = PublicStorefrontCheck::query()
                ->where('status', PublicStorefrontCheck::STATUS_QUEUED)
                ->where('queued_at', '<=', $now)
                ->orderBy('queued_at')
                ->orderBy('id');
            /** @var PublicStorefrontCheck|null $check */
            $check = (DB::getDriverName() === 'pgsql' ? $query->lock('for update skip locked') : $query->lockForUpdate())->first();

            if ($check === null) {
                return null;
            }

            $token = bin2hex(random_bytes(32));
            $fencing = $check->fencing_token + 1;
            $deadline = $now->copy()->addSeconds((int) config('watchdog.public_check.absolute_run_seconds'));
            $leaseUntil = $this->leaseUntil($now, $deadline);

            $check->forceFill([
                'status' => PublicStorefrontCheck::STATUS_RUNNING,
                'started_at' => $check->started_at ?? $now,
                'lease_token_hash' => hash('sha256', $token),
                'fencing_token' => $fencing,
                'lease_until' => $leaseUntil,
                'absolute_deadline_at' => $deadline,
                'browser_version' => $browserVersion,
                'location' => $location,
            ])->save();
            $worker->forceFill(['last_seen_at' => $now])->save();

            return [
                'type' => self::TYPE,
                'attempt_id' => $check->id,
                'run_id' => $check->id,
                'fencing_token' => $fencing,
                'lease_token' => $token,
                'lease_until' => $leaseUntil->toJSON(),
                'absolute_deadline_at' => $deadline->toJSON(),
                'scenario' => [
                    'adapter_version' => 'public-storefront@1',
                    'platform' => 'public',
                    'store_origin' => $check->normalized_origin,
                    'target_url' => $check->normalized_origin,
                    'capture_steps' => true,
                    'max_artifacts' => (int) config('watchdog.public_check.max_artifacts'),
                    'steps' => [
                        ['code' => 'public_home', 'timeout_ms' => 15000],
                        ['code' => 'public_checkout', 'timeout_ms' => 20000],
                    ],
                ],
                'network_policy' => [
                    'allowed_origins' => [$check->normalized_origin],
                    'quiet_origins' => [],
                ],
            ];
        });
    }

    /**
     * @return array{lease_until: string, absolute_deadline_at: string}
     */
    public function heartbeat(BrowserWorker $worker, string $checkId, string $leaseToken, int $fencingToken): array
    {
        return DB::transaction(function () use ($checkId, $leaseToken, $fencingToken): array {
            $check = $this->lockedCheck($checkId, $leaseToken, $fencingToken);
            $now = Carbon::now();

            if ($check->status !== PublicStorefrontCheck::STATUS_RUNNING || $check->lease_until === null || $check->lease_until->lessThan($now)) {
                throw new LeaseConflict(self::ERROR_LEASE_EXPIRED);
            }

            $check->forceFill(['lease_until' => $this->leaseUntil($now, $check->absolute_deadline_at)])->save();

            return [
                'lease_until' => $check->lease_until->toJSON(),
                'absolute_deadline_at' => $check->absolute_deadline_at->toJSON(),
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function complete(BrowserWorker $worker, string $checkId, string $leaseToken, array $result): void
    {
        $resultHash = hash('sha256', json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        DB::transaction(function () use ($checkId, $leaseToken, $result, $resultHash): void {
            $check = $this->lockedCheck($checkId, $leaseToken, (int) $result['fencing_token']);

            if ($check->result_hash !== null) {
                if (! hash_equals($check->result_hash, $resultHash)) {
                    throw new LeaseConflict(self::ERROR_RESULT_CONFLICT);
                }

                return;
            }

            $now = Carbon::now();

            if ($check->status !== PublicStorefrontCheck::STATUS_RUNNING || $check->lease_until === null || $check->lease_until->lessThan($now)) {
                throw new LeaseConflict(self::ERROR_LEASE_EXPIRED);
            }

            $artifactIds = array_values(array_unique($result['artifact_ids'] ?? []));

            if ($artifactIds !== [] && PublicStorefrontCheckArtifact::query()
                ->where('check_id', $check->id)
                ->where('state', PublicStorefrontCheckArtifact::STATE_READY)
                ->whereIn('id', $artifactIds)
                ->count() !== count($artifactIds)) {
                throw new LeaseConflict(self::ERROR_ARTIFACT_UNKNOWN, 422);
            }

            $status = $result['status'];
            $check->forceFill([
                'status' => $status,
                'verdict' => $this->verdict($status, $result['error_code'] ?? null, $result['steps'] ?? []),
                'error_code' => $result['error_code'] ?? null,
                'steps' => $result['steps'] ?? [],
                'diagnostics' => $result['diagnostics'] ?? [],
                'finished_at' => $now,
                'result_hash' => $resultHash,
                'expires_at' => $now->copy()->addMinutes((int) config('watchdog.public_check.artifact_ttl_minutes')),
            ])->save();

            PublicStorefrontCheckArtifact::query()
                ->where('check_id', $check->id)
                ->update(['expires_at' => $check->expires_at]);
        });
    }

    public function storeScreenshot(BrowserWorker $worker, string $checkId, string $leaseToken, int $fencingToken, string $contentType, string $sha256, string $redactionVersion, string $body): PublicStorefrontCheckArtifact
    {
        if (strlen($body) === 0 || strlen($body) > (int) config('watchdog.public_check.max_artifact_bytes')) {
            throw new LeaseConflict(self::ERROR_TOO_LARGE, 413);
        }

        $signature = self::SIGNATURES[$contentType] ?? null;

        if ($signature === null || ! str_starts_with($body, $signature[0]) || preg_match('/^[a-z0-9.\-]{1,32}$/', $redactionVersion) !== 1) {
            throw new LeaseConflict(self::ERROR_TYPE_INVALID, 422);
        }

        if (preg_match('/^[0-9a-f]{64}$/', $sha256) !== 1 || ! hash_equals(hash('sha256', $body), $sha256)) {
            throw new LeaseConflict(self::ERROR_CHECKSUM_MISMATCH, 422);
        }

        return DB::transaction(function () use ($checkId, $leaseToken, $fencingToken, $contentType, $sha256, $redactionVersion, $body, $signature): PublicStorefrontCheckArtifact {
            $check = $this->lockedCheck($checkId, $leaseToken, $fencingToken);

            if ($check->status !== PublicStorefrontCheck::STATUS_RUNNING || $check->lease_until === null || $check->lease_until->lessThan(Carbon::now())) {
                throw new LeaseConflict(self::ERROR_LEASE_EXPIRED);
            }

            if (PublicStorefrontCheckArtifact::query()->where('check_id', $check->id)->count() >= (int) config('watchdog.public_check.max_artifacts')) {
                throw new LeaseConflict(self::ERROR_LIMIT_REACHED, 409);
            }

            $id = (string) Str::uuid7();
            $key = implode('/', ['public-diagnostics', $check->id, $id.'.'.$signature[1]]);

            try {
                $this->disk()->put($key, $body, ['visibility' => 'private', 'ContentType' => $contentType]);
                $stored = $this->disk()->size($key);
            } catch (Throwable $exception) {
                report($exception);

                throw new LeaseConflict(self::ERROR_STORAGE_UNAVAILABLE, 503);
            }

            if ($stored !== strlen($body)) {
                $this->disk()->delete($key);

                throw new LeaseConflict(self::ERROR_STORAGE_UNAVAILABLE, 503);
            }

            return PublicStorefrontCheckArtifact::query()->forceCreate([
                'id' => $id,
                'check_id' => $check->id,
                'kind' => PublicStorefrontCheckArtifact::KIND_SCREENSHOT,
                'object_key' => $key,
                'content_type' => $contentType,
                'size_bytes' => strlen($body),
                'sha256' => $sha256,
                'redaction_version' => $redactionVersion,
                'state' => PublicStorefrontCheckArtifact::STATE_READY,
                'created_at' => Carbon::now(),
                'expires_at' => $check->expires_at,
            ]);
        });
    }

    public function recoverExpired(int $limit = 50): int
    {
        $now = Carbon::now();
        $ids = PublicStorefrontCheck::query()
            ->where('status', PublicStorefrontCheck::STATUS_RUNNING)
            ->where('lease_until', '<', $now)
            ->orderBy('lease_until')
            ->limit($limit)
            ->pluck('id');

        foreach ($ids as $id) {
            PublicStorefrontCheck::query()->whereKey($id)->where('status', PublicStorefrontCheck::STATUS_RUNNING)->update([
                'status' => PublicStorefrontCheck::STATUS_INCONCLUSIVE,
                'verdict' => PublicStorefrontCheck::VERDICT_INSUFFICIENT_DATA,
                'error_code' => self::ERROR_LEASE_EXPIRED,
                'finished_at' => $now,
            ]);
        }

        return $ids->count();
    }

    public function deleteCheck(string $id): void
    {
        DB::transaction(function () use ($id): void {
            /** @var PublicStorefrontCheck|null $check */
            $check = PublicStorefrontCheck::query()->whereKey($id)->lockForUpdate()->first();

            if ($check === null) {
                return;
            }

            foreach ($check->artifacts()->where('state', PublicStorefrontCheckArtifact::STATE_READY)->get() as $artifact) {
                $this->disk()->delete($artifact->object_key);
                $artifact->forceFill(['state' => PublicStorefrontCheckArtifact::STATE_DELETED, 'deleted_at' => Carbon::now()])->save();
            }

            if ($check->expires_at->lessThanOrEqualTo(Carbon::now())) {
                $check->delete();
            }
        });
    }

    private function lockedCheck(string $checkId, string $leaseToken, int $fencingToken): PublicStorefrontCheck
    {
        /** @var PublicStorefrontCheck|null $check */
        $check = PublicStorefrontCheck::query()->whereKey($checkId)->lockForUpdate()->first();

        if ($check === null || $check->lease_token_hash === null) {
            throw new LeaseConflict(self::ERROR_LEASE_NOT_FOUND, 404);
        }

        if (! hash_equals($check->lease_token_hash, hash('sha256', $leaseToken))) {
            throw new LeaseConflict(self::ERROR_LEASE_TOKEN_INVALID);
        }

        if ($check->fencing_token !== $fencingToken) {
            throw new LeaseConflict(self::ERROR_STALE_FENCING_TOKEN);
        }

        return $check;
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     */
    private function verdict(string $status, ?string $errorCode, array $steps): string
    {
        if ($status === PublicStorefrontCheck::STATUS_PASSED) {
            return PublicStorefrontCheck::VERDICT_CHECKOUT_REACHED;
        }

        if ($status === PublicStorefrontCheck::STATUS_BLOCKED || in_array($errorCode, ['waf_challenge', 'blocked_external_origin'], true)) {
            return PublicStorefrontCheck::VERDICT_BLOCKED;
        }

        if ($status === PublicStorefrontCheck::STATUS_FAILED && $errorCode === 'site_failure') {
            return PublicStorefrontCheck::VERDICT_PUBLIC_ISSUE;
        }

        foreach ($steps as $step) {
            if (($step['error_code'] ?? null) === 'adapter_unsupported') {
                return PublicStorefrontCheck::VERDICT_NEEDS_CONNECTION;
            }
        }

        return PublicStorefrontCheck::VERDICT_INSUFFICIENT_DATA;
    }

    private function leaseUntil(Carbon $now, Carbon $absoluteDeadline): Carbon
    {
        $lease = $now->copy()->addSeconds((int) config('watchdog.public_check.lease_seconds'));
        $cap = $absoluteDeadline->copy()->addSeconds((int) config('watchdog.public_check.result_grace_seconds'));

        return $lease->lessThan($cap) ? $lease : $cap;
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('watchdog.artifacts.disk'));
    }
}
