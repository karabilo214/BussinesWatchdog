<?php

namespace App\Http\Dto\Payments;

use App\Models\PaymentAllocation;

class PaymentAllocationDto
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(PaymentAllocation $allocation): array
    {
        return [
            'id' => $allocation->id,
            'store_id' => $allocation->store_id,
            'order_id' => $allocation->order_id,
            'payment_id' => $allocation->payment_id,
            'capture_transaction_id' => $allocation->capture_transaction_id,
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
