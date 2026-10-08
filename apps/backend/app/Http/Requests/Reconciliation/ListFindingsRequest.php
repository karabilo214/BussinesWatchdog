<?php

namespace App\Http\Requests\Reconciliation;

use App\Models\ReconciliationFinding;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListFindingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'status' => ['sometimes', Rule::in([
                ReconciliationFinding::STATUS_OK,
                ReconciliationFinding::STATUS_PENDING,
                ReconciliationFinding::STATUS_MISMATCH,
                ReconciliationFinding::STATUS_UNSUPPORTED,
                ReconciliationFinding::STATUS_UNKNOWN,
            ])],
            'rule_code' => ['sometimes', 'string', 'max:64'],
            'currency' => ['sometimes', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'string'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('currency')) {
            $this->merge(['currency' => strtoupper(trim((string) $this->input('currency')))]);
        }
    }
}
