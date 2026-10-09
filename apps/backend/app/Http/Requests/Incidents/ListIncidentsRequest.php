<?php

namespace App\Http\Requests\Incidents;

use App\Models\Incident;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListIncidentsRequest extends FormRequest
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
            'store_id' => ['sometimes', 'uuid'],
            'state' => ['sometimes', 'array', 'min:1', 'max:3'],
            'state.*' => ['string', Rule::in([
                Incident::STATE_OPEN,
                Incident::STATE_ACKNOWLEDGED,
                Incident::STATE_RESOLVED,
            ])],
            'severity' => ['sometimes', Rule::in([
                Incident::SEVERITY_INFO,
                Incident::SEVERITY_WARNING,
                Incident::SEVERITY_CRITICAL,
            ])],
            'family' => ['sometimes', 'string', 'max:64'],
            'currency' => ['sometimes', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'string'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('state'))) {
            $this->merge(['state' => array_values(array_unique(explode(',', $this->input('state'))))]);
        }

        if ($this->has('currency')) {
            $this->merge(['currency' => strtoupper(trim((string) $this->input('currency')))]);
        }
    }
}
