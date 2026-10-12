<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformStaff extends Model
{
    use HasUuids;

    public const ROLE_OWNER = 'platform_owner';

    public const ROLE_ADMIN = 'platform_admin';

    public const ROLE_SUPPORT = 'platform_support';

    public const ROLE_BILLING = 'platform_billing';

    public const ROLES = [self::ROLE_OWNER, self::ROLE_ADMIN, self::ROLE_SUPPORT, self::ROLE_BILLING];

    public const STATE_ACTIVE = 'active';

    public const STATE_DISABLED = 'disabled';

    public const UPDATED_AT = null;

    protected $table = 'platform_staff';

    protected $fillable = ['user_id', 'role', 'state', 'mfa_required', 'permission_version', 'disabled_at'];

    protected function casts(): array
    {
        return [
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
