<?php

namespace App\Support\Reconciliation;

use App\Models\ReconciliationDirtySubject;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class DirtySubjectMarker
{
    public const COALESCE_SECONDS = 30;

    public const BULK_CHUNK = 500;

    public function markOrder(string $tenantId, string $storeId, string $orderId, string $reason, ?DateTimeInterface $dueAt = null): void
    {
        $this->mark($tenantId, $storeId, ReconciliationDirtySubject::TYPE_ORDER, $orderId, $reason, $dueAt);
    }

    public function markStoreUnmatchedPayments(string $tenantId, string $storeId, string $reason, ?DateTimeInterface $dueAt = null): void
    {
        $this->mark($tenantId, $storeId, ReconciliationDirtySubject::TYPE_STORE_UNMATCHED_PAYMENTS, $storeId, $reason, $dueAt);
    }

    /**
     * @param  list<string>  $orderIds
     */
    public function markOrdersIfAbsent(string $tenantId, string $storeId, array $orderIds, string $reason): int
    {
        $now = Carbon::now();
        $inserted = 0;

        foreach (array_chunk($orderIds, self::BULK_CHUNK) as $chunk) {
            $inserted += ReconciliationDirtySubject::query()->insertOrIgnore(array_map(fn (string $orderId): array => [
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'store_id' => $storeId,
                'subject_type' => ReconciliationDirtySubject::TYPE_ORDER,
                'subject_id' => $orderId,
                'reason' => $reason,
                'mark_version' => 1,
                'first_marked_at' => $now,
                'last_marked_at' => $now,
                'due_at' => $now,
                'attempts' => 0,
            ], $chunk));
        }

        return $inserted;
    }

    private function mark(string $tenantId, string $storeId, string $type, string $subjectId, string $reason, ?DateTimeInterface $dueAt): void
    {
        $now = Carbon::now();
        $due = $dueAt !== null ? Carbon::instance($dueAt) : $now->copy()->addSeconds(self::COALESCE_SECONDS);

        $inserted = ReconciliationDirtySubject::query()->insertOrIgnore([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'store_id' => $storeId,
            'subject_type' => $type,
            'subject_id' => $subjectId,
            'reason' => $reason,
            'mark_version' => 1,
            'first_marked_at' => $now,
            'last_marked_at' => $now,
            'due_at' => $due,
            'attempts' => 0,
        ]);

        if ($inserted > 0) {
            return;
        }

        /** @var ReconciliationDirtySubject $existing */
        $existing = ReconciliationDirtySubject::query()
            ->where('tenant_id', $tenantId)
            ->where('subject_type', $type)
            ->where('subject_id', $subjectId)
            ->lockForUpdate()
            ->firstOrFail();

        $existing->forceFill([
            'reason' => $reason,
            'mark_version' => $existing->mark_version + 1,
            'last_marked_at' => $now,
            'due_at' => $due->lessThan($existing->due_at) ? $due : $existing->due_at,
        ])->save();
    }
}
