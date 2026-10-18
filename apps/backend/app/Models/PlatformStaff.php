<?php

namespace App\Models;

use App\Support\Platform\PlatformCapabilities;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformStaff extends Model
{
    use HasUuids;

    public const ROLE_OWNER = 'platform_owner';

    public const ROLE_MEMBER = 'platform_member';

    /** Former fixed roles, now names of capability presets (ADR 0064); saving one stores a member with that preset. */
    public const ROLE_ADMIN = 'platform_admin';

    public const ROLE_SUPPORT = 'platform_support';

    public const ROLE_BILLING = 'platform_billing';

    public const ROLES = [self::ROLE_OWNER, self::ROLE_MEMBER];

    public const STATE_ACTIVE = 'active';

    public const STATE_DISABLED = 'disabled';

    public const UPDATED_AT = null;

    protected $table = 'platform_staff';

    protected $fillable = ['user_id', 'role', 'capabilities', 'state', 'mfa_required', 'permission_version', 'disabled_at'];

    protected static function booted(): void
    {
        static::saving(fn (self $staff) => self::settleRole($staff));
    }

    /**
     * An owner has no own capability list; a member always has one; a preset name becomes a member with that preset.
     */
    public static function settleRole(Model $model): void
    {
        $preset = PlatformCapabilities::PRESETS[$model->role] ?? null;

        if ($preset !== null) {
            $model->role = self::ROLE_MEMBER;
            $model->capabilities ??= $preset;
        }

        $model->capabilities = $model->role === self::ROLE_OWNER ? null : PlatformCapabilities::normalize((array) ($model->capabilities ?? []));
    }

    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'mfa_required' => 'boolean',
            'permission_version' => 'integer',
            'created_at' => 'datetime',
            'disabled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function active(): bool
    {
        return $this->state === self::STATE_ACTIVE;
    }
}
