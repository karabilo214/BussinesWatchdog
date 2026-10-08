<?php

namespace App\Http\Dto\Reconciliation;

use App\Models\ReconciliationFinding;
use Illuminate\Support\Collection;

class ReconciliationFindingDto
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(ReconciliationFinding $finding): array
    {
        return [
            'id' => $finding->id,
            'run_id' => $finding->run_id,
            'order_id' => $finding->order_id,
            'payment_id' => $finding->payment_id,
            'rule_code' => $finding->rule_code,
            'status' => $finding->status,
            'reason_code' => $finding->reason_code,
            'currency' => $finding->currency,
            'currency_exponent' => $finding->currency_exponent,
            'expected_minor' => $this->moneyOrNull($finding->expected_minor),
            'actual_minor' => $this->moneyOrNull($finding->actual_minor),
            'difference_minor' => $this->moneyOrNull($finding->difference_minor),
            'gross_minor' => $this->moneyOrNull($finding->gross_minor),
            'captured_minor' => $this->moneyOrNull($finding->captured_minor),
            'refund_expected_minor' => $this->moneyOrNull($finding->refund_expected_minor),
            'refund_actual_minor' => $this->moneyOrNull($finding->refund_actual_minor),
            'evidence' => $finding->evidence ?? [],
            'evaluated_at' => $finding->evaluated_at?->toJSON(),
        ];
    }

    /**
     * @param  Collection<int, ReconciliationFinding>  $findings
     * @return list<array<string, mixed>>
     */
    public function collection(Collection $findings): array
    {
        return $findings
            ->map(fn (ReconciliationFinding $finding): array => $this->toArray($finding))
            ->all();
    }

    private function moneyOrNull(?int $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
