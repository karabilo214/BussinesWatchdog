<?php

namespace App\Support\Outbox;

use App\Models\DomainOutbox;
use Illuminate\Support\Facades\DB;

class DomainOutboxSweeper
{
    public const ERROR_LEASE_EXPIRED = 'outbox_lease_expired';

    public function releaseExpiredLeases(int $limit = 100): int
    {
        $limit = max(1, min($limit, 1000));

        return DB::transaction(function () use ($limit): int {
            $now = now();
            $messages = DomainOutbox::query()
                ->where('status', DomainOutbox::STATUS_LEASED)
                ->where('lease_until', '<=', $now)
                ->orderBy('lease_until')
                ->orderBy('id')
                ->limit($limit)
                ->lockForUpdate()
                ->get();

            foreach ($messages as $message) {
                $message->forceFill([
                    'status' => DomainOutbox::STATUS_PENDING,
                    'lease_until' => null,
                    'next_attempt_at' => $now,
                    'error_code' => self::ERROR_LEASE_EXPIRED,
                ])->save();
            }

            return $messages->count();
        });
    }
}
