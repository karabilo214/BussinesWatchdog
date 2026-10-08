<?php

namespace App\Http\Dto\Integrations;

use App\Models\Integration;
use Illuminate\Support\Collection;

class IntegrationDto
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Integration $integration): array
    {
        $integration->loadMissing('credentials');

        return [
            'id' => $integration->id,
            'store_id' => $integration->store_id,
            'provider' => $integration->provider,
            'external_account_id' => $integration->external_account_id,
            'install_id' => $integration->install_id,
            'mode' => $integration->mode,
            'source_authority' => $integration->source_authority,
            'status' => $integration->status,
            'capabilities' => $integration->capabilities ?? [],
            'api_version' => $integration->api_version,
            'connector_version' => $integration->connector_version,
            'last_heartbeat_at' => $integration->last_heartbeat_at?->toJSON(),
            'last_successful_sync_at' => $integration->last_successful_sync_at?->toJSON(),
            'health' => $integration->health ?? [],
            'credentials' => $integration->credentials
                ->map(fn ($credential): array => [
                    'id' => $credential->id,
                    'kind' => $credential->kind,
                    'key_id_suffix' => substr($credential->key_id, -8),
                    'key_version' => $credential->key_version,
                    'status' => $credential->status,
                    'expires_at' => $credential->expires_at?->toJSON(),
                    'rotated_at' => $credential->rotated_at?->toJSON(),
                    'created_at' => $credential->created_at?->toJSON(),
                ])
                ->all(),
            'created_at' => $integration->created_at?->toJSON(),
            'updated_at' => $integration->updated_at?->toJSON(),
        ];
    }

    /**
     * @param Collection<int, Integration> $integrations
     * @return array<int, array<string, mixed>>
     */
    public function collection(Collection $integrations): array
    {
        return $integrations
            ->map(fn (Integration $integration): array => $this->toArray($integration))
            ->all();
    }
}
