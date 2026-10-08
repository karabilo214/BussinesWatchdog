<?php

namespace App\Http\Dto\Incidents;

use App\Models\Suppression;

class SuppressionDto
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Suppression $suppression): array
    {
        return [
            'id' => $suppression->id,
            'incident_id' => $suppression->incident_id,
            'reason' => $suppression->reason,
            'created_by' => $suppression->created_by,
            'starts_at' => $suppression->starts_at?->toJSON(),
            'ends_at' => $suppression->ends_at?->toJSON(),
            'revoked_at' => $suppression->revoked_at?->toJSON(),
            'created_at' => $suppression->created_at?->toJSON(),
        ];
    }
}
