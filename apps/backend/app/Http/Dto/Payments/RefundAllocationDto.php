<?php

namespace App\Http\Dto\Payments;

use App\Models\RefundAllocation;

class RefundAllocationDto
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(RefundAllocation $allocation): array
    {
        return [
            'id' => $allocation->id,
            'store_id' => $allocation->store_id,
            'refund_id' => $allocation->refund_id,
            'refund_transaction_id' => $allocation->refund_transaction_id,
            'payment_allocation_id' => $allocation->payment_allocation_id,
            'currency' => $allocation->currency,
            'amount_minor' => (string) $allocation->amount_minor,
            'strategy' => $allocation->strategy,
            'evidence' => $allocation->evidence ?? [],
            'created_by' => $allocation->created_by,
            'revoked_at' => $allocation->revoked_at?->toJSON(),
            'created_at' => $allocation->created_at?->toJSON(),
        ];
    }
}
