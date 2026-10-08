<?php

namespace App\Http\Dto\StoreVerifications;

use App\Models\StoreVerification;

class StoreVerificationDto
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(StoreVerification $verification): array
    {
        return [
            'id' => $verification->id,
            'store_id' => $verification->store_id,
            'method' => $verification->method,
            'state' => $verification->status,
            'verified_origin' => $verification->verified_origin,
            'expires_at' => $verification->expires_at->toJSON(),
            'verified_at' => $verification->verified_at?->toJSON(),
            'reason_code' => null,
        ];
    }
}
