<?php

namespace App\Http\Dto\Disputes;

use App\Models\Integration;
use App\Models\ProviderDispute;

class DisputeDto
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(ProviderDispute $dispute, bool $showMoneyAmounts): array
    {
        return [
            'id' => $dispute->id,
            'external_id' => $dispute->external_id,
            'payment_id' => $dispute->payment_id,
            'provider' => Integration::query()->whereKey($dispute->integration_id)->value('provider'),
            'mode' => $dispute->mode,
            'status' => $dispute->status,
            'open' => $dispute->isOpen(),
            'reason' => $dispute->reason,
            'currency' => $dispute->currency,
            'currency_exponent' => $dispute->currency_exponent,
            'amount_minor' => $showMoneyAmounts ? (string) $dispute->amount_minor : null,
            'money_amounts_hidden' => ! $showMoneyAmounts,
            'evidence_due_by' => $dispute->evidence_due_by?->toJSON(),
            'opened_at' => $dispute->opened_at?->toJSON(),
            'closed_at' => $dispute->closed_at?->toJSON(),
        ];
    }
}
