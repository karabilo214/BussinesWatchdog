<?php

namespace App\Http\Dto\Payments;

use App\Models\FinancialTransaction;

class FinancialTransactionDto
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(FinancialTransaction $transaction): array
    {
        return [
            'id' => $transaction->id,
            'payment_id' => $transaction->payment_id,
            'external_operation_id' => $transaction->external_operation_id,
            'kind' => $transaction->kind,
            'status' => $transaction->status,
            'currency' => $transaction->currency,
            'currency_exponent' => $transaction->currency_exponent,
            'amount_minor' => (string) $transaction->amount_minor,
            'occurred_at' => $transaction->occurred_at?->toJSON(),
        ];
    }
}
