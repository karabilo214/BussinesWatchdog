<?php

namespace App\Models;

use App\Support\Auth\TenantPermissions;
use App\Support\Auth\TenantRoles;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Membership extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = null;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'role',
        'permissions',
    ];

    /** The owner has no list; a member created with only a preset role gets that preset with amounts (ADR 0079). */
    protected static function booted(): void
    {
        static::creating(function (Membership $membership): void {
            if ($membership->role === TenantRoles::OWNER) {
                $membership->permissions = null;
            } elseif ($membership->permissions === null) {
                $membership->permissions = TenantPermissions::preset((string) $membership->role, true);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'permissions' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
