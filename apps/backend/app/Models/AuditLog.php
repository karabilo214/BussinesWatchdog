<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    use HasUuids;

    public const ACTOR_USER = 'user';

    public const ACTOR_CONNECTOR = 'connector';

    public const ENTITY_INTEGRATION = 'integration';

    public const ENTITY_PAYMENT_ALLOCATION = 'payment_allocation';

    public const ENTITY_REFUND_ALLOCATION = 'refund_allocation';

    public const ACTION_INTEGRATION_PAIRED = 'integration.paired';

    public const ACTION_INTEGRATION_REVOKED = 'integration.revoked';

    public const ACTION_PAYMENT_ALLOCATION_CREATED = 'payment_allocation.created';

    public const ACTION_PAYMENT_ALLOCATION_REVOKED = 'payment_allocation.revoked';

    public const ACTION_REFUND_ALLOCATION_CREATED = 'refund_allocation.created';

    public const ACTION_REFUND_ALLOCATION_REVOKED = 'refund_allocation.revoked';

    public $timestamps = false;

    protected $table = 'audit_log';

    protected $fillable = [
        'tenant_id',
        'store_id',
        'actor_user_id',
        'actor_type',
        'action',
        'entity_type',
        'entity_id',
        'changes',
        'request_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
