<?php

namespace App\Http\Dto\Incidents;

use App\Models\IncidentActivity;
use Illuminate\Support\Collection;

class IncidentActivityDto
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(IncidentActivity $activity): array
    {
        return [
            'id' => $activity->id,
            'incident_id' => $activity->incident_id,
            'kind' => $activity->kind,
            'actor_id' => $activity->actor_id,
            'incident_revision' => $activity->incident_revision,
            'data' => $activity->sanitized_data ?? [],
            'created_at' => $activity->created_at?->toJSON(),
        ];
    }

    /**
     * @param  Collection<int, IncidentActivity>  $activities
     * @return list<array<string, mixed>>
     */
    public function collection(Collection $activities): array
    {
        return $activities
            ->map(fn (IncidentActivity $activity): array => $this->toArray($activity))
            ->all();
    }
}
