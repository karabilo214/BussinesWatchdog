<?php

namespace App\Http\Dto\Reconciliation;

use App\Models\ReconciliationFinding;
use Illuminate\Support\Collection;

class ReconciliationFindingDto
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(ReconciliationFinding $finding, bool $showMoneyAmounts = true): array
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
            'mode' => $finding->mode,
            'currency_exponent' => $finding->currency_exponent,
            'expected_minor' => $showMoneyAmounts ? $this->moneyOrNull($finding->expected_minor) : null,
            'actual_minor' => $showMoneyAmounts ? $this->moneyOrNull($finding->actual_minor) : null,
            'difference_minor' => $showMoneyAmounts ? $this->moneyOrNull($finding->difference_minor) : null,
            'gross_minor' => $showMoneyAmounts ? $this->moneyOrNull($finding->gross_minor) : null,
            'captured_minor' => $showMoneyAmounts ? $this->moneyOrNull($finding->captured_minor) : null,
            'refund_expected_minor' => $showMoneyAmounts ? $this->moneyOrNull($finding->refund_expected_minor) : null,
            'refund_actual_minor' => $showMoneyAmounts ? $this->moneyOrNull($finding->refund_actual_minor) : null,
            'evidence' => $showMoneyAmounts ? ($finding->evidence ?? []) : [],
            'money_amounts_hidden' => ! $showMoneyAmounts,
            'evaluated_at' => $finding->evaluated_at?->toJSON(),
        ];
    }

    /**
     * @param  Collection<int, ReconciliationFinding>  $findings
     * @return list<array<string, mixed>>
     */
    public function collection(Collection $findings, bool $showMoneyAmounts = true): array
    {
        return $findings
            ->map(fn (ReconciliationFinding $finding): array => $this->toArray($finding, $showMoneyAmounts))
            ->all();
    }

    private function moneyOrNull(?int $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
