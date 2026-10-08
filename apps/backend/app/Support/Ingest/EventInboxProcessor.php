<?php

namespace App\Support\Ingest;

use App\Models\EventInbox;
use Illuminate\Support\Facades\DB;

class EventInboxProcessor
{
    public function processReceived(string $eventInboxId): bool
    {
        return DB::transaction(function () use ($eventInboxId): bool {
            /** @var EventInbox|null $event */
            $event = EventInbox::query()
                ->whereKey($eventInboxId)
                ->lockForUpdate()
                ->first();

            if ($event === null) {
                return false;
            }

            if ($event->status === EventInbox::STATUS_PROCESSED) {
                return true;
            }

            if ($event->status !== EventInbox::STATUS_RECEIVED) {
                return false;
            }

            $event->forceFill([
                'status' => EventInbox::STATUS_PROCESSED,
                'processing_lease_until' => null,
                'processed_at' => now(),
                'error_code' => null,
            ])->save();

            return true;
        });
    }
}
