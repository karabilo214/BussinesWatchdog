<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationDelivery extends Model
{
    use HasUuids;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_UNCERTAIN = 'uncertain';

    public const STATUS_FAILED = 'failed';

    public const STATUS_DEAD_LETTER = 'dead_letter';

    public const STATUS_SUPPRESSED = 'suppressed';

    public const STATUSES = [
        self::STATUS_QUEUED,
        self::STATUS_SENDING,
        self::STATUS_SENT,
        self::STATUS_UNCERTAIN,
        self::STATUS_FAILED,
        self::STATUS_DEAD_LETTER,
        self::STATUS_SUPPRESSED,
    ];

    public const DUE_STATUSES = [self::STATUS_QUEUED, self::STATUS_FAILED];

    public const KIND_INCIDENT_OPENED = 'incident_opened';

    public const KIND_INCIDENT_REOPENED = 'incident_reopened';

    public const KIND_INCIDENT_RECOVERED = 'incident_recovered';

    public const KIND_TEST = 'test';

    public const INCIDENT_KINDS = [
        self::KIND_INCIDENT_OPENED,
        self::KIND_INCIDENT_REOPENED,
        self::KIND_INCIDENT_RECOVERED,
    ];

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'incident_id',
        'channel_id',
        'notification_kind',
        'dedupe_key',
        'incident_revision',
        'template_version',
        'sanitized_content',
        'status',
        'attempts',
        'next_attempt_at',
        'provider_message_id',
        'sent_at',
        'error_code',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'incident_revision' => 'integer',
            'sanitized_content' => 'array',
            'attempts' => 'integer',
            'next_attempt_at' => 'datetime',
            'sent_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(NotificationChannel::class, 'channel_id');
    }
}
