<?php

namespace App\Support\Browser;

use App\Models\Artifact;
use App\Models\BrowserWorker;
use App\Models\CheckAttempt;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ArtifactStore
{
    public const ERROR_TOO_LARGE = 'artifact_too_large';

    public const ERROR_TYPE_INVALID = 'artifact_type_invalid';

    public const ERROR_CHECKSUM_MISMATCH = 'artifact_checksum_mismatch';

    public const ERROR_LIMIT_REACHED = 'artifact_limit_reached';

    public const ERROR_STORAGE_UNAVAILABLE = 'artifact_storage_unavailable';

    private const SIGNATURES = [
        'image/jpeg' => ["\xFF\xD8\xFF", 'jpg'],
        'image/png' => ["\x89PNG\r\n\x1A\n", 'png'],
    ];

    public function __construct(
        private readonly BrowserLeaseService $leases,
    ) {}

    /**
     * Stores a redacted screenshot sent by the worker of a live attempt. The worker never gets
     * storage credentials or URLs: the backend chooses the object key, checks size, type and
     * SHA-256, writes the private object and only then records it as ready.
     */
    public function storeScreenshot(BrowserWorker $worker, string $attemptId, string $leaseToken, int $fencingToken, string $contentType, string $sha256, string $redactionVersion, string $body): Artifact
    {
        if (strlen($body) === 0 || strlen($body) > (int) config('watchdog.artifacts.max_bytes')) {
            throw new LeaseConflict(self::ERROR_TOO_LARGE, 413);
        }

        $signature = self::SIGNATURES[$contentType] ?? null;

        if ($signature === null || ! str_starts_with($body, $signature[0]) || preg_match('/^[a-z0-9.\-]{1,32}$/', $redactionVersion) !== 1) {
            throw new LeaseConflict(self::ERROR_TYPE_INVALID, 422);
        }

        if (preg_match('/^[0-9a-f]{64}$/', $sha256) !== 1 || ! hash_equals(hash('sha256', $body), $sha256)) {
            throw new LeaseConflict(self::ERROR_CHECKSUM_MISMATCH, 422);
        }

        return DB::transaction(function () use ($worker, $attemptId, $leaseToken, $fencingToken, $contentType, $sha256, $redactionVersion, $body, $signature): Artifact {
            $attempt = $this->leases->activeAttempt($worker, $attemptId, $leaseToken, $fencingToken);

            if (Artifact::query()->where('attempt_id', $attempt->id)->count() >= (int) config('watchdog.artifacts.max_per_attempt')) {
                throw new LeaseConflict(self::ERROR_LIMIT_REACHED, 409);
            }

            $id = (string) Str::uuid7();
            $key = $this->objectKey($attempt, $id, $signature[1]);

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

            return Artifact::query()->forceCreate([
                'id' => $id,
                'tenant_id' => $attempt->tenant_id,
                'store_id' => $attempt->store_id,
                'attempt_id' => $attempt->id,
                'kind' => Artifact::KIND_SCREENSHOT,
                'object_key' => $key,
                'content_type' => $contentType,
                'size_bytes' => strlen($body),
                'sha256' => $sha256,
                'redaction_version' => $redactionVersion,
                'state' => Artifact::STATE_READY,
                'expires_at' => Carbon::now()->addDays((int) config('watchdog.artifacts.retention_days')),
                'created_at' => Carbon::now(),
            ]);
        });
    }

    /**
     * @return array{url: string, expires_at: string}
     */
    public function temporaryUrl(Artifact $artifact): array
    {
        $expiresAt = Carbon::now()->addSeconds((int) config('watchdog.artifacts.download_ttl_seconds'));

        return [
            'url' => $this->disk()->temporaryUrl($artifact->object_key, $expiresAt, [
                'ResponseContentType' => $artifact->content_type,
                'ResponseContentDisposition' => 'inline',
            ]),
            'expires_at' => $expiresAt->toJSON(),
        ];
    }

    /**
     * Deletes expired artifacts (object first, then the row is marked deleted; safe to rerun).
     */
    public function purgeExpired(int $limit = 500): int
    {
        $purged = 0;

        foreach (Artifact::query()->where('state', Artifact::STATE_READY)->where('expires_at', '<=', Carbon::now())->orderBy('expires_at')->limit($limit)->get() as $artifact) {
            $this->disk()->delete($artifact->object_key);
            $artifact->forceFill(['state' => Artifact::STATE_DELETED, 'deleted_at' => Carbon::now()])->save();
            $purged++;
        }

        return $purged;
    }

    private function objectKey(CheckAttempt $attempt, string $id, string $extension): string
    {
        return implode('/', ['tenants', $attempt->tenant_id, 'stores', $attempt->store_id, 'checks', $attempt->run_id, $attempt->id, $id.'.'.$extension]);
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('watchdog.artifacts.disk'));
    }
}
