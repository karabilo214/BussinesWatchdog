<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class BillingEventInbox extends Model
{
    use HasUuids;

    public const STATUS_RECEIVED = 'received';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_IGNORED = 'ignored';

    public const STATUS_QUARANTINED = 'quarantined';

    public const STATUS_DEAD_LETTER = 'dead_letter';

    public const SOURCE_WEBHOOK = 'webhook';

    public const SOURCE_EVENTS_API = 'events_api';

    public $timestamps = false;

    protected $table = 'billing_event_inbox';

    protected $fillable = ['provider', 'provider_account_ref', 'mode', 'provider_event_ref', 'event_type', 'entity_type', 'entity_ref', 'tenant_id', 'sanitized_payload', 'payload_hash', 'api_version', 'source', 'status', 'attempts', 'next_attempt_at', 'lease_until', 'occurred_at', 'received_at', 'processed_at', 'error_code'];

    protected function casts(): array
    {
        return [
            'sanitized_payload' => 'array',
            'attempts' => 'integer',
            'next_attempt_at' => 'datetime',
            'lease_until' => 'datetime',
            'occurred_at' => 'datetime',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
