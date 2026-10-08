<?php

namespace App\Support\Outbox;

use App\Models\DomainOutbox;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DomainOutboxLeaser
{
    /**
     * @return Collection<int, DomainOutbox>
     */
    public function leaseDue(int $limit = 50, int $leaseSeconds = 60): Collection
    {
        $limit = max(1, min($limit, 100));
        $leaseSeconds = max(1, min($leaseSeconds, 3600));

        return DB::transaction(function () use ($limit, $leaseSeconds): Collection {
            $now = now();

            $messages = DomainOutbox::query()
                ->where(function ($query) use ($now): void {
                    $query
                        ->where(function ($pending) use ($now): void {
                            $pending
                                ->where('status', DomainOutbox::STATUS_PENDING)
                                ->where('next_attempt_at', '<=', $now);
                        })
                        ->orWhere(function ($expiredLease) use ($now): void {
                            $expiredLease
                                ->where('status', DomainOutbox::STATUS_LEASED)
                                ->where('lease_until', '<=', $now);
                        });
                })
                ->orderBy('next_attempt_at')
                ->orderBy('created_at')
                ->orderBy('id')
                ->limit($limit)
                ->lockForUpdate()
                ->get();

            $leaseUntil = $now->copy()->addSeconds($leaseSeconds);

            foreach ($messages as $message) {
                $message->forceFill([
                    'status' => DomainOutbox::STATUS_LEASED,
                    'attempts' => $message->attempts + 1,
                    'lease_until' => $leaseUntil,
                    'error_code' => null,
                ])->save();
            }

            return $messages->map(fn (DomainOutbox $message): DomainOutbox => $message->refresh());
        });
    }
}
