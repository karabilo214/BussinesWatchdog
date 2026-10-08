<?php

namespace App\Support\Outbox;

use App\Models\DomainOutbox;
use Illuminate\Support\Facades\DB;

class DomainOutboxResultRecorder
{
    public function markPublished(string $messageId): bool
    {
        return DB::transaction(function () use ($messageId): bool {
            $message = $this->currentLease($messageId);

            if ($message === null) {
                return false;
            }

            $message->forceFill([
                'status' => DomainOutbox::STATUS_PUBLISHED,
                'lease_until' => null,
                'published_at' => now(),
                'error_code' => null,
            ])->save();

            return true;
        });
    }

    public function markFailed(string $messageId, string $errorCode, int $maxAttempts = 5, int $retryDelaySeconds = 60): bool
    {
        $maxAttempts = max(1, $maxAttempts);
        $retryDelaySeconds = max(1, min($retryDelaySeconds, 86400));

        return DB::transaction(function () use ($messageId, $errorCode, $maxAttempts, $retryDelaySeconds): bool {
            $message = $this->currentLease($messageId);

            if ($message === null) {
                return false;
            }

            $status = $message->attempts >= $maxAttempts
                ? DomainOutbox::STATUS_DEAD_LETTER
                : DomainOutbox::STATUS_PENDING;

            $message->forceFill([
                'status' => $status,
                'lease_until' => null,
                'next_attempt_at' => now()->addSeconds($retryDelaySeconds),
                'error_code' => $errorCode,
            ])->save();

            return true;
        });
    }

    private function currentLease(string $messageId): ?DomainOutbox
    {
        /** @var DomainOutbox|null $message */
        $message = DomainOutbox::query()
            ->whereKey($messageId)
            ->lockForUpdate()
            ->first();

        if ($message === null || $message->status !== DomainOutbox::STATUS_LEASED) {
            return null;
        }

        if ($message->lease_until === null || $message->lease_until->isPast()) {
            return null;
        }

        return $message;
    }
}
