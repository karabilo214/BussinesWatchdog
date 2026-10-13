<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PlatformStaffInvitation extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    public const TTL_HOURS = 72;

    protected $fillable = ['email', 'role', 'token_hash', 'invited_by', 'expires_at', 'accepted_at', 'accepted_staff_id', 'revoked_at'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function pending(): bool
    {
        return $this->accepted_at === null && $this->revoked_at === null && $this->expires_at->isFuture();
    }
}
