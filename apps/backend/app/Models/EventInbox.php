<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventInbox extends Model
{
    use HasUuids;

    public const STATUS_RECEIVED = 'received';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_QUARANTINED = 'quarantined';

    public const STATUS_DEAD_LETTER = 'dead_letter';

    public const EVENT_ORDER_SNAPSHOT = 'order.snapshot';

    public const EVENT_ORDER_DELETED = 'order.deleted';

    public const EVENT_REFUND_SNAPSHOT = 'refund.snapshot';

    public const EVENT_PAYMENT_SNAPSHOT = 'payment.snapshot';

    public const EVENT_TRANSACTION_OBSERVED = 'transaction.observed';

    public const EVENT_INTEGRATION_HEARTBEAT = 'integration.heartbeat';

    public const EVENT_INTEGRATION_CAPABILITIES_CHANGED = 'integration.capabilities_changed';

    public const EVENT_DEPLOYMENT_OBSERVED = 'deployment.observed';

    public const EVENT_FUNNEL_OBSERVED = 'funnel.observed';

    public const AGGREGATE_ORDER = 'order';

    public const AGGREGATE_REFUND = 'refund';

    public const AGGREGATE_PAYMENT = 'payment';

    public const AGGREGATE_TRANSACTION = 'transaction';

    public const AGGREGATE_INTEGRATION = 'integration';

    public const AGGREGATE_DEPLOYMENT = 'deployment';

    public const AGGREGATE_SESSION = 'session';

    public const EVENT_TYPES = [
        self::EVENT_ORDER_SNAPSHOT,
        self::EVENT_ORDER_DELETED,
        self::EVENT_REFUND_SNAPSHOT,
        self::EVENT_PAYMENT_SNAPSHOT,
        self::EVENT_TRANSACTION_OBSERVED,
        self::EVENT_INTEGRATION_HEARTBEAT,
        self::EVENT_INTEGRATION_CAPABILITIES_CHANGED,
        self::EVENT_DEPLOYMENT_OBSERVED,
        self::EVENT_FUNNEL_OBSERVED,
    ];

    public const AGGREGATE_TYPES = [
        self::AGGREGATE_ORDER,
        self::AGGREGATE_REFUND,
        self::AGGREGATE_PAYMENT,
        self::AGGREGATE_TRANSACTION,
        self::AGGREGATE_INTEGRATION,
        self::AGGREGATE_DEPLOYMENT,
        self::AGGREGATE_SESSION,
    ];

    public $timestamps = false;

    protected $table = 'event_inbox';

    protected $fillable = [
        'tenant_id',
        'store_id',
        'integration_id',
        'provider_event_id',
        'schema_version',
        'event_type',
        'aggregate_type',
        'aggregate_external_id',
        'aggregate_revision',
        'occurred_at',
        'observed_at',
        'received_at',
        'is_synthetic',
        'payload',
        'payload_hash',
        'canonicalization_version',
        'status',
        'attempt_count',
        'next_attempt_at',
        'processing_lease_until',
        'processed_at',
        'error_code',
        'request_id',
    ];

    protected function casts(): array
    {
        return [
            'aggregate_revision' => 'integer',
            'occurred_at' => 'datetime',
            'observed_at' => 'datetime',
            'received_at' => 'datetime',
            'is_synthetic' => 'boolean',
            'payload' => 'array',
            'canonicalization_version' => 'integer',
            'attempt_count' => 'integer',
            'next_attempt_at' => 'datetime',
            'processing_lease_until' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    public static function supportsEventType(string $eventType): bool
    {
        return in_array($eventType, self::EVENT_TYPES, true);
    }

    public static function supportsAggregateType(string $aggregateType): bool
    {
        return in_array($aggregateType, self::AGGREGATE_TYPES, true);
    }
}
