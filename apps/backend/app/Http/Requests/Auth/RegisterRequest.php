<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
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
            'name' => ['required', 'string', 'min:1', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'confirmed', 'max:128', Password::min(12)],
            'organization_name' => ['required_without:invitation_token', 'string', 'min:1', 'max:100'],
            'timezone' => ['required_without:invitation_token', 'timezone'],
            'invitation_token' => ['sometimes', 'string', 'max:128'],
            'locale' => ['sometimes', 'string', 'in:ru,en,de'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge([
                'email' => mb_strtolower(trim((string) $this->input('email'))),
            ]);
        }

        if (! $this->has('locale')) {
            $this->merge(['locale' => 'ru']);
        }
    }
}
