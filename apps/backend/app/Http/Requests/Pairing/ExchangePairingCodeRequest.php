<?php

namespace App\Http\Requests\Pairing;

use Illuminate\Foundation\Http\FormRequest;

class ExchangePairingCodeRequest extends FormRequest
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
            'pairing_code' => ['required', 'string', 'min:16', 'max:128'],
            'install_id' => ['required', 'uuid'],
            'connector_code' => ['required', 'string', 'min:2', 'max:64', 'regex:/^[a-z][a-z0-9_]{1,63}$/'],
            'plugin_version' => ['required', 'string', 'min:1', 'max:100'],
            'base_url' => ['required', 'url', 'max:2048', 'starts_with:https://', function (string $attribute, mixed $value, \Closure $fail): void {
                $parts = parse_url((string) $value);

                if (($parts['user'] ?? null) !== null || ($parts['pass'] ?? null) !== null) {
                    $fail("The {$attribute} must not include credentials.");
                }

                if (($parts['fragment'] ?? null) !== null) {
                    $fail("The {$attribute} must not include a fragment.");
                }
            }],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('connector_code')) {
            $this->merge([
                'connector_code' => str_replace('-', '_', mb_strtolower(trim((string) $this->input('connector_code')))),
            ]);
        }

        if ($this->has('base_url')) {
            $this->merge([
                'base_url' => rtrim(trim((string) $this->input('base_url')), '/'),
            ]);
        }
    }
}
