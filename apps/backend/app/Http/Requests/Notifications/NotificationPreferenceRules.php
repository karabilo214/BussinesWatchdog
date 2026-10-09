<?php

namespace App\Http\Requests\Notifications;

use App\Support\Notifications\NotificationPreferences;
use Illuminate\Validation\Rule;

final class NotificationPreferenceRules
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'preferences' => ['sometimes', 'array'],
            'preferences.min_severity' => ['sometimes', Rule::in(NotificationPreferences::SEVERITIES)],
            'preferences.locale' => ['sometimes', Rule::in(NotificationPreferences::LOCALES)],
            'preferences.timezone' => ['sometimes', 'timezone:all'],
            'preferences.quiet_hours' => ['sometimes', 'nullable', 'array'],
            'preferences.quiet_hours.start' => ['required_with:preferences.quiet_hours', 'date_format:H:i'],
            'preferences.quiet_hours.end' => ['required_with:preferences.quiet_hours', 'date_format:H:i'],
            'preferences.critical_bypasses_quiet_hours' => ['sometimes', 'boolean'],
            'preferences.notify_recovery' => ['sometimes', 'boolean'],
            'preferences.store_ids' => ['sometimes', 'nullable', 'array', 'max:100'],
            'preferences.store_ids.*' => ['uuid'],
        ];
    }
}
