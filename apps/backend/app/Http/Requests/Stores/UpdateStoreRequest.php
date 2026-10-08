<?php

namespace App\Http\Requests\Stores;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStoreRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'min:1', 'max:100'],
            'base_url' => ['sometimes', 'url', 'max:2048', 'starts_with:https://', function (string $attribute, mixed $value, \Closure $fail): void {
                $parts = parse_url((string) $value);

                if (($parts['user'] ?? null) !== null || ($parts['pass'] ?? null) !== null) {
                    $fail("The {$attribute} must not include credentials.");
                }

                if (($parts['fragment'] ?? null) !== null) {
                    $fail("The {$attribute} must not include a fragment.");
                }
            }],
            'timezone' => ['sometimes', 'timezone'],
            'locale' => ['sometimes', 'string', 'in:ru,en,de'],
            'default_currency' => ['sometimes', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'status' => ['sometimes', 'string', 'in:paused,active'],
            'browser_enabled' => ['sometimes', 'boolean'],
            'telemetry_enabled' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('base_url')) {
            $this->merge([
                'base_url' => rtrim(trim((string) $this->input('base_url')), '/'),
            ]);
        }

        if ($this->has('default_currency')) {
            $this->merge([
                'default_currency' => strtoupper(trim((string) $this->input('default_currency'))),
            ]);
        }
    }
}
