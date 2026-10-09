<?php

namespace App\Http\Dto\Notifications;

use App\Models\NotificationChannel;
use App\Models\Tenant;
use App\Support\Notifications\NotificationChannelService;
use App\Support\Notifications\NotificationPreferences;
use Illuminate\Support\Collection;

class NotificationChannelDto
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(NotificationChannel $channel, Tenant $tenant): array
    {
        return [
            'id' => $channel->id,
            'kind' => $channel->kind,
            'label' => $channel->label,
            'destination_masked' => NotificationChannelService::maskDestination($channel),
            'enabled' => $channel->enabled,
            'verified_at' => $channel->verified_at?->toJSON(),
            'preferences' => $channel->preferences ?? [],
            'effective_preferences' => NotificationPreferences::fromArray(
                $channel->preferences ?? [],
                (string) $tenant->locale,
                (string) $tenant->timezone,
            )->toArray(),
            'health' => (object) ($channel->health ?? []),
            'created_at' => $channel->created_at?->toJSON(),
            'updated_at' => $channel->updated_at?->toJSON(),
        ];
    }

    /**
     * @param  Collection<int, NotificationChannel>  $channels
     * @return list<array<string, mixed>>
     */
    public function collection(Collection $channels, Tenant $tenant): array
    {
        return $channels->map(fn (NotificationChannel $channel): array => $this->toArray($channel, $tenant))->values()->all();
    }
}
