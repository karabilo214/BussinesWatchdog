<?php

namespace App\Support\Reconciliation;

use App\Models\FinancialTransaction;
use App\Models\PaymentAllocation;
use App\Models\ReconciliationDirtySubject;
use App\Models\ReconciliationFinding;
use App\Models\ReconciliationRun;
use Illuminate\Support\Carbon;

class GraceRecheckScheduler
{
    public function __construct(
        private readonly DirtySubjectMarker $marker,
    ) {}

    public function scheduleFor(ReconciliationRun $run): ?Carbon
    {
        $orderId = $run->scope['order_id'] ?? null;

        if (is_string($orderId)) {
            return $this->scheduleOrder($run, $orderId);
        }

        if (($run->scope['rule_code'] ?? null) === ReconciliationFinding::RULE_PAYMENT_WITHOUT_ORDER) {
            return $this->scheduleUnmatchedPayments($run);
        }

        return null;
    }

    private function scheduleOrder(ReconciliationRun $run, string $orderId): ?Carbon
    {
        $deadline = ReconciliationFinding::query()
            ->where('tenant_id', $run->tenant_id)
            ->where('run_id', $run->id)
            ->where('status', ReconciliationFinding::STATUS_PENDING)
            ->get()
            ->map(fn (ReconciliationFinding $finding): ?string => $finding->evidence['grace_deadline_at'] ?? null)
            ->filter()
            ->map(fn (string $value): Carbon => Carbon::parse($value))
            ->sort()
            ->first();

        if ($deadline !== null) {
            $this->marker->markOrder($run->tenant_id, $run->store_id, $orderId, ReconciliationDirtySubject::REASON_GRACE_DEADLINE, $deadline);
        }

        return $deadline;
    }

    private function scheduleUnmatchedPayments(ReconciliationRun $run): ?Carbon
    {
        $allocatedCaptureIds = PaymentAllocation::query()
            ->where('tenant_id', $run->tenant_id)
            ->where('store_id', $run->store_id)
            ->whereNull('revoked_at')
            ->select('capture_transaction_id');

        $earliestWithinGrace = FinancialTransaction::query()
            ->where('tenant_id', $run->tenant_id)
            ->where('store_id', $run->store_id)
            ->where('kind', 'capture')
            ->where('status', 'succeeded')
            ->where('occurred_at', '>', Carbon::now()->subHours(UnmatchedPaymentScanner::ORPHAN_GRACE_HOURS))
            ->whereNotIn('id', $allocatedCaptureIds)
            ->min('occurred_at');

        if ($earliestWithinGrace === null) {
            return null;
        }

        $deadline = Carbon::parse($earliestWithinGrace)->addHours(UnmatchedPaymentScanner::ORPHAN_GRACE_HOURS);
        $this->marker->markStoreUnmatchedPayments($run->tenant_id, $run->store_id, ReconciliationDirtySubject::REASON_GRACE_DEADLINE, $deadline);

        return $deadline;
    }
}
