<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SupportAccessGrant extends Model
{
    use HasUuids;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const DENIED = 'denied';

    public const REVOKED = 'revoked';

    public const EXPIRED = 'expired';

    public const CANCELLED = 'cancelled';

    public const READ_DIAGNOSTICS = 'read_diagnostics';

    public const READ_ARTIFACTS = 'read_artifacts';

    public const REQUEST_RECHECK = 'request_recheck';

    public const EDIT_STORE = 'edit_store';

    public const SCOPES = [self::READ_DIAGNOSTICS, self::READ_ARTIFACTS, self::REQUEST_RECHECK, self::EDIT_STORE];

    protected $fillable = [
        'tenant_id', 'store_id', 'staff_id', 'requested_by_staff_id', 'requested_by_user_id',
        'approved_by', 'scopes', 'reason', 'case_reference', 'state', 'duration_hours',
        'version', 'expires_at', 'approved_at', 'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'duration_hours' => 'integer',
            'version' => 'integer',
            'expires_at' => 'datetime',
            'approved_at' => 'datetime',
            'revoked_at' => 'datetime',
            'staff_hidden_at' => 'datetime',
        ];
    }
}
