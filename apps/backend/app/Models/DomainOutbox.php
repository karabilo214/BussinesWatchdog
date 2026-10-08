<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DomainOutbox extends Model
{
    use HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_LEASED = 'leased';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_DEAD_LETTER = 'dead_letter';

    public const TOPIC_EVENT_INBOX_RECEIVED = 'event_inbox.received';

    public $timestamps = false;

    protected $table = 'domain_outbox';

    protected $fillable = [
        'tenant_id',
        'topic',
        'dedupe_key',
        'payload',
        'status',
        'attempts',
        'next_attempt_at',
        'lease_until',
        'published_at',
        'error_code',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'next_attempt_at' => 'datetime',
            'lease_until' => 'datetime',
            'published_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
