<?php

namespace App\Http\Dto\Orders;

use App\Models\OrderRevision;

class OrderRevisionDto
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(OrderRevision $revision): array
    {
        $data = $revision->snapshot['data'] ?? [];

        return [
            'source_revision' => $revision->source_revision,
            'status' => is_string($data['status'] ?? null) ? $data['status'] : null,
            'total_minor' => isset($data['total_minor']) ? (string) $data['total_minor'] : null,
            'observed_at' => $revision->observed_at?->toJSON(),
        ];
    }
}
