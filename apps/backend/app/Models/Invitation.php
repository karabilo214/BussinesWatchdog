<?php

namespace App\Models;

use App\Support\Auth\TenantPermissions;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invitation extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'email',
        'role',
        'permissions',
        'token_hash',
        'invited_by',
        'expires_at',
        'accepted_at',
        'revoked_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (Invitation $invitation): void {
            $invitation->permissions ??= TenantPermissions::preset((string) $invitation->role, true);
        });
    }

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
