<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PlatformStaffInvitation extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    public const TTL_HOURS = 72;

    public const PURPOSE_JOIN = 'join';

    /** A link to connect a new authenticator after the platform owner reset it (ADR 0052). */
    public const PURPOSE_MFA_RESET = 'mfa_reset';

    protected $fillable = ['email', 'role', 'capabilities', 'purpose', 'staff_id', 'token_hash', 'invited_by', 'expires_at', 'accepted_at', 'accepted_staff_id', 'revoked_at'];

    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(fn (self $invitation) => PlatformStaff::settleRole($invitation));
    }

    public function pending(): bool
    {
        return $this->accepted_at === null && $this->revoked_at === null && $this->expires_at->isFuture();
    }
}
