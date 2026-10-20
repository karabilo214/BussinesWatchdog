<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DataDeletionRequest extends Model
{
    use HasUuids;

    public const STATUS_REQUESTED = 'requested';

    public const STATUS_REVOKING = 'revoking';

    public const STATUS_DELETING_OBJECTS = 'deleting_objects';

    public const STATUS_PURGING = 'purging';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_FAILED = 'failed';

    public const OPEN_STATUSES = [self::STATUS_REQUESTED, self::STATUS_REVOKING, self::STATUS_DELETING_OBJECTS, self::STATUS_PURGING, self::STATUS_FAILED];

    public const VIA_OWNER = 'owner';

    public const VIA_PLATFORM = 'platform';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'requested_by',
        'requested_via',
        'reason',
        'previous_status',
        'status',
        'cursor',
        'error_code',
        'attempts',
        'requested_at',
        'purge_after',
        'lease_until',
        'cancelled_at',
        'cancelled_by',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'cursor' => 'array',
            'attempts' => 'integer',
            'requested_at' => 'datetime',
            'purge_after' => 'datetime',
            'lease_until' => 'datetime',
            'cancelled_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
