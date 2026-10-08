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

    public const EVENT_TYPES = [
        'order.snapshot',
        'order.deleted',
        'refund.snapshot',
        'payment.snapshot',
        'transaction.observed',
        'integration.heartbeat',
        'integration.capabilities_changed',
        'deployment.observed',
        'funnel.observed',
    ];

    public const AGGREGATE_TYPES = [
        'order',
        'refund',
        'payment',
        'transaction',
        'integration',
        'deployment',
        'session',
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
