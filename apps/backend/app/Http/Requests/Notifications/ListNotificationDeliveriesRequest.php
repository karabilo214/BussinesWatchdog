<?php

namespace App\Http\Requests\Notifications;

use App\Models\NotificationDelivery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListNotificationDeliveriesRequest extends FormRequest
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
            'incident_id' => ['sometimes', 'uuid'],
            'channel_id' => ['sometimes', 'uuid'],
            'status' => ['sometimes', Rule::in(NotificationDelivery::STATUSES)],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'string'],
        ];
    }
}
