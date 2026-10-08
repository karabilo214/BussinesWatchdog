<?php

namespace App\Http\Dto\Orders;

use App\Models\Refund;

class RefundDto
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Refund $refund): array
    {
        return [
            'id' => $refund->id,
            'order_id' => $refund->order_id,
            'external_id' => $refund->external_id,
            'currency' => $refund->currency,
            'currency_exponent' => $refund->currency_exponent,
            'amount_minor' => (string) $refund->amount_minor,
            'external_required' => $refund->external_required,
            'provider_ref' => $refund->provider_ref,
            'status' => $refund->status,
            'occurred_at' => $refund->occurred_at?->toJSON(),
            'updated_at' => $refund->updated_at?->toJSON(),
        ];
    }
}
