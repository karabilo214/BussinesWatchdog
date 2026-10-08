<?php

namespace App\Http\Dto\Incidents;

use App\Models\Incident;
use Illuminate\Support\Collection;

class IncidentDto
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Incident $incident): array
    {
        return [
            'id' => $incident->id,
            'store_id' => $incident->store_id,
            'family' => $incident->family,
            'component' => $incident->component,
            'state' => $incident->state,
            'severity' => $incident->severity,
            'title_code' => $incident->title_code,
            'currency' => $incident->currency,
            'verified_discrepancy_minor' => $incident->verified_discrepancy_minor === null
                ? null
                : (string) $incident->verified_discrepancy_minor,
            'first_seen_at' => $incident->first_seen_at?->toJSON(),
            'last_seen_at' => $incident->last_seen_at?->toJSON(),
            'last_good_at' => $incident->last_good_at?->toJSON(),
            'first_bad_at' => $incident->first_bad_at?->toJSON(),
            'acknowledged_by' => $incident->acknowledged_by,
            'acknowledged_at' => $incident->acknowledged_at?->toJSON(),
            'resolved_at' => $incident->resolved_at?->toJSON(),
            'resolution_reason' => $incident->resolution_reason,
            'revision' => $incident->revision,
            'created_at' => $incident->created_at?->toJSON(),
            'updated_at' => $incident->updated_at?->toJSON(),
        ];
    }

    /**
     * @param  Collection<int, Incident>  $incidents
     * @return list<array<string, mixed>>
     */
    public function collection(Collection $incidents): array
    {
        return $incidents
            ->map(fn (Incident $incident): array => $this->toArray($incident))
            ->all();
    }
}
