<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventInbox extends Model
{
    use HasUuids;

    public const STATUS_RECEIVED = 'received';

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
}
