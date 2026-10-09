<?php

namespace App\Support\Reconciliation;

use App\Models\Order;
use App\Models\ReconciliationDirtySubject;
use App\Models\ReconciliationRun;
use App\Models\Store;
use App\Support\Incidents\MoneyIncidentCorrelator;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class DirtySubjectProcessor
{
    public const TRIGGER = 'scheduled';

    public const ERROR_PROCESSING_FAILED = 'dirty_subject_processing_failed';

    public const MAX_RETRY_DELAY_SECONDS = 3600;

    public function __construct(
        private readonly OrderReconciliationService $orderService,
        private readonly UnmatchedPaymentScanner $scanner,
        private readonly MoneyIncidentCorrelator $correlator,
        private readonly GraceRecheckScheduler $graceScheduler,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * @return array{claimed: int, processed: int, skipped: int, failed: int}
     */
    public function processDue(int $limit = 100, int $leaseSeconds = 120): array
    {
        $claimed = $this->claimDue(max(1, min($limit, 500)), max(30, min($leaseSeconds, 3600)));
        $totals = ['claimed' => $claimed->count(), 'processed' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($claimed as $subject) {
            try {
                $run = $this->tenantContext->scope(
                    $subject->tenant_id,
                    'scheduler',
                    fn (): ?ReconciliationRun => $this->evaluate($subject),
                );
            } catch (Throwable $exception) {
                report($exception);
                $this->fail($subject);
                $totals['failed']++;

                continue;
            }

            $this->complete($subject);

            if ($run === null) {
                $totals['skipped']++;

                continue;
            }

            $this->graceScheduler->scheduleFor($run);
            $totals['processed']++;
        }

        return $totals;
    }

    /**
     * @return Collection<int, ReconciliationDirtySubject>
     */
    private function claimDue(int $limit, int $leaseSeconds): Collection
    {
        return DB::transaction(function () use ($limit, $leaseSeconds): Collection {
            $now = Carbon::now();

            $subjects = ReconciliationDirtySubject::query()
                ->where('due_at', '<=', $now)
                ->where(fn ($query) => $query->whereNull('lease_until')->orWhere('lease_until', '<=', $now))
                ->orderBy('due_at')
                ->orderBy('id')
                ->limit($limit)
                ->lockForUpdate()
                ->get();

            foreach ($subjects as $subject) {
                $subject->forceFill([
                    'lease_until' => $now->copy()->addSeconds($leaseSeconds),
                    'attempts' => $subject->attempts + 1,
                ])->save();
            }

            return $subjects;
        });
    }

    private function evaluate(ReconciliationDirtySubject $subject): ?ReconciliationRun
    {
        if ($subject->subject_type === ReconciliationDirtySubject::TYPE_ORDER) {
            /** @var Order|null $order */
            $order = Order::query()
                ->where('tenant_id', $subject->tenant_id)
                ->where('store_id', $subject->store_id)
                ->whereKey($subject->subject_id)
                ->first();

            if ($order === null) {
                return null;
            }

            $run = $this->orderService->evaluate($order, self::TRIGGER);
        } else {
            /** @var Store|null $store */
            $store = Store::query()
                ->where('tenant_id', $subject->tenant_id)
                ->whereKey($subject->subject_id)
                ->first();

            if ($store === null) {
                return null;
            }

            $run = $this->scanner->scan($store, self::TRIGGER);
        }

        $this->correlator->correlate($run);

        return $run;
    }

    private function complete(ReconciliationDirtySubject $claimed): void
    {
        DB::transaction(function () use ($claimed): void {
            /** @var ReconciliationDirtySubject|null $locked */
            $locked = ReconciliationDirtySubject::query()->whereKey($claimed->id)->lockForUpdate()->first();

            if ($locked === null) {
                return;
            }

            if ($locked->mark_version === $claimed->mark_version) {
                $locked->delete();

                return;
            }

            $locked->forceFill([
                'lease_until' => null,
                'attempts' => 0,
                'error_code' => null,
            ])->save();
        });
    }

    private function fail(ReconciliationDirtySubject $claimed): void
    {
        DB::transaction(function () use ($claimed): void {
            /** @var ReconciliationDirtySubject|null $locked */
            $locked = ReconciliationDirtySubject::query()->whereKey($claimed->id)->lockForUpdate()->first();

            if ($locked === null) {
                return;
            }

            $delay = min(self::MAX_RETRY_DELAY_SECONDS, 60 * (2 ** min(max(0, $locked->attempts - 1), 10)));

            $locked->forceFill([
                'lease_until' => null,
                'due_at' => Carbon::now()->addSeconds($delay),
                'error_code' => self::ERROR_PROCESSING_FAILED,
            ])->save();
        });
    }
}
