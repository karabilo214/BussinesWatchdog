<?php

namespace App\Http\Requests\Notifications;

use App\Models\NotificationChannel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateNotificationChannelRequest extends FormRequest
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
            'kind' => ['required', Rule::in([NotificationChannel::KIND_EMAIL, NotificationChannel::KIND_TELEGRAM])],
            'label' => ['required', 'string', 'min:1', 'max:100'],
            'destination_email' => ['required_if:kind,'.NotificationChannel::KIND_EMAIL, 'nullable', 'email:rfc', 'max:254'],
        ], NotificationPreferenceRules::rules());
    }
}
