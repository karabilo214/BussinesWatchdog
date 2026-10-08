<?php

namespace App\Http\Dto\Payments;

use App\Models\Payment;

class PaymentDto
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Payment $payment): array
    {
        return [
            'id' => $payment->id,
            'store_id' => $payment->store_id,
            'integration_id' => $payment->integration_id,
            'external_id' => $payment->external_id,
            'intent_ref' => $payment->intent_ref,
            'charge_ref' => $payment->charge_ref,
            'mode' => $payment->mode,
            'currency' => $payment->currency,
            'currency_exponent' => $payment->currency_exponent,
            'status' => $payment->status,
            'source_authority' => $payment->source_authority,
            'source_updated_at' => $payment->source_updated_at?->toJSON(),
            'created_at' => $payment->created_at?->toJSON(),
            'updated_at' => $payment->updated_at?->toJSON(),
        ];
    }
}
