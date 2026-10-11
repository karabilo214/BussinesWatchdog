<?php

namespace App\Http\Requests\Exports;

use App\Models\ExportFile;
use App\Models\Incident;
use App\Models\ReconciliationFinding;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateExportRequest extends FormRequest
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
            'kind' => ['required', Rule::in([
                ExportFile::KIND_FINDINGS,
                ExportFile::KIND_INCIDENTS,
            ])],
            'format' => ['required', Rule::in([ExportFile::FORMAT_CSV])],
            'filters' => ['sometimes', 'array'],
            'filters.from' => ['sometimes', 'date'],
            'filters.to' => ['sometimes', 'date'],
            'filters.current' => ['sometimes', 'boolean'],
            'filters.status' => ['sometimes', Rule::in([
                ReconciliationFinding::STATUS_OK,
                ReconciliationFinding::STATUS_PENDING,
                ReconciliationFinding::STATUS_MISMATCH,
                ReconciliationFinding::STATUS_UNSUPPORTED,
                ReconciliationFinding::STATUS_UNKNOWN,
            ])],
            'filters.rule_code' => ['sometimes', 'string', 'max:64'],
            'filters.state' => ['sometimes', 'array', 'min:1', 'max:3'],
            'filters.state.*' => ['string', Rule::in([
                Incident::STATE_OPEN,
                Incident::STATE_ACKNOWLEDGED,
                Incident::STATE_RESOLVED,
            ])],
            'filters.severity' => ['sometimes', Rule::in([
                Incident::SEVERITY_INFO,
                Incident::SEVERITY_WARNING,
                Incident::SEVERITY_CRITICAL,
            ])],
            'filters.family' => ['sometimes', 'string', 'max:64'],
            'filters.currency' => ['sometimes', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $filters = $this->input('filters', []);

        if (! is_array($filters)) {
            return;
        }

        if (isset($filters['currency'])) {
            $filters['currency'] = strtoupper(trim((string) $filters['currency']));
        }

        if (isset($filters['state']) && is_string($filters['state'])) {
            $filters['state'] = array_values(array_unique(explode(',', $filters['state'])));
        }

        $this->merge(['filters' => $filters]);
    }
}
