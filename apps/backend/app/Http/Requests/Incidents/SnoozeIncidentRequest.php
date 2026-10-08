<?php

namespace App\Http\Requests\Incidents;

use Illuminate\Foundation\Http\FormRequest;

class SnoozeIncidentRequest extends FormRequest
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
            'until' => ['required', 'date', 'after:now'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }
}
