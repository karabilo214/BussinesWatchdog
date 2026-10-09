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

    public const ENTITY_NOTIFICATION_CHANNEL = 'notification_channel';

    public const ACTION_INTEGRATION_PAIRED = 'integration.paired';

    public const ACTION_INTEGRATION_REVOKED = 'integration.revoked';

    public const ACTION_INTEGRATION_PAIRING_FAILED = 'integration.pairing_failed';

    public const ACTION_INTEGRATION_CREDENTIAL_ROTATION_REQUESTED = 'integration.credential_rotation_requested';

    public const ACTION_INTEGRATION_CREDENTIAL_ROTATED = 'integration.credential_rotated';

    public const ACTION_INTEGRATION_CREDENTIAL_DRAINED = 'integration.credential_drained';

    public const ENTITY_PAIRING_CODE = 'pairing_code';

    public const ENTITY_STORE = 'store';

    public const ACTION_STORE_VERIFIED = 'store.verified';

    public const ACTION_PAYMENT_ALLOCATION_CREATED = 'payment_allocation.created';

    public const ACTION_PAYMENT_ALLOCATION_REVOKED = 'payment_allocation.revoked';

    public const ACTION_REFUND_ALLOCATION_CREATED = 'refund_allocation.created';

    public const ACTION_REFUND_ALLOCATION_REVOKED = 'refund_allocation.revoked';

    public const ACTION_NOTIFICATION_CHANNEL_CREATED = 'notification_channel.created';

    public const ACTION_NOTIFICATION_CHANNEL_UPDATED = 'notification_channel.updated';

    public const ACTION_NOTIFICATION_CHANNEL_VERIFIED = 'notification_channel.verified';

    public const ACTION_CHECK_SCENARIO_SAVED = 'check_scenario.saved';

    public const ACTION_CHECK_RUN_CANCELLED = 'check_run.cancelled';

    public const ENTITY_CHECK_SCENARIO = 'check_scenario';

    public const ENTITY_CHECK_RUN = 'check_run';

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
