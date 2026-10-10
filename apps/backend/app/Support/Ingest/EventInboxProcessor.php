<?php

namespace App\Support\Ingest;

use App\Models\EventInbox;
use App\Support\Payments\ExactReferenceMatcher;
use App\Support\Projections\FinancialTransactionProjector;
use App\Support\Projections\OrderDeletedProjector;
use App\Support\Projections\OrderSnapshotProjector;
use App\Support\Projections\PaymentAttemptsProjector;
use App\Support\Projections\PaymentSnapshotProjector;
use App\Support\Projections\RefundSnapshotProjector;
use App\Support\Reconciliation\EventDirtyMarker;
use Illuminate\Support\Facades\DB;

class EventInboxProcessor
{
    private ?string $lastErrorCode = null;

    public function __construct(
        private readonly OrderSnapshotProjector $orderSnapshotProjector,
        private readonly OrderDeletedProjector $orderDeletedProjector,
        private readonly RefundSnapshotProjector $refundSnapshotProjector,
        private readonly PaymentSnapshotProjector $paymentSnapshotProjector,
        private readonly FinancialTransactionProjector $financialTransactionProjector,
        private readonly PaymentAttemptsProjector $paymentAttemptsProjector,
        private readonly EventDirtyMarker $dirtyMarker,
        private readonly ExactReferenceMatcher $matcher,
    ) {}

    public function processReceived(string $eventInboxId): bool
    {
        $this->lastErrorCode = null;

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

            if (! $this->recordProjectionResult($this->orderSnapshotProjector->project($event))) {
                return false;
            }

            if (! $this->recordProjectionResult($this->orderDeletedProjector->project($event))) {
                return false;
            }

            if (! $this->recordProjectionResult($this->refundSnapshotProjector->project($event))) {
                return false;
            }

            if (! $this->recordProjectionResult($this->paymentSnapshotProjector->project($event))) {
                return false;
            }

            if (! $this->recordProjectionResult($this->financialTransactionProjector->project($event))) {
                return false;
            }

            if (! $this->recordProjectionResult($this->paymentAttemptsProjector->project($event))) {
                return false;
            }

            $this->matcher->matchForEvent($event);
            $this->dirtyMarker->markForEvent($event);

            $event->forceFill([
                'status' => EventInbox::STATUS_PROCESSED,
                'processing_lease_until' => null,
                'processed_at' => now(),
                'error_code' => null,
            ])->save();

            return true;
        });
    }

    public function lastErrorCode(): ?string
    {
        return $this->lastErrorCode;
    }

    private function recordProjectionResult(EventProjectionResult $result): bool
    {
        if ($result->ok) {
            return true;
        }

        $this->lastErrorCode = $result->errorCode ?? EventProjectionResult::ERROR_PROJECTION_FAILED;

        return false;
    }
}
