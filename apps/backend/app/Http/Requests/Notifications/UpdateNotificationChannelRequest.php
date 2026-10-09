<?php

namespace App\Http\Requests\Notifications;

use Illuminate\Foundation\Http\FormRequest;

class UpdateNotificationChannelRequest extends FormRequest
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
        return array_merge([
            'label' => ['sometimes', 'string', 'min:1', 'max:100'],
            'enabled' => ['sometimes', 'boolean'],
            'destination_email' => ['sometimes', 'email:rfc', 'max:254'],
        ], NotificationPreferenceRules::rules());
    }
}
