<?php

namespace App\Http\Requests\StoreVerifications;

use App\Models\StoreVerification;
use Illuminate\Foundation\Http\FormRequest;

class CreateStoreVerificationRequest extends FormRequest
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
            'method' => [
                'required',
                'string',
                'in:'.implode(',', [
                    StoreVerification::METHOD_WORDPRESS_CHALLENGE,
                    StoreVerification::METHOD_DNS,
                ]),
            ],
        ];
    }
}
