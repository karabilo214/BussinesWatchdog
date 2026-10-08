<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncidentActivity extends Model
{
    use HasUuids;

    public const KIND_CREATED = 'created';

    public const KIND_SIGNAL_LINKED = 'signal_linked';

    public const KIND_ACKNOWLEDGED = 'acknowledged';

    public const KIND_COMMENT = 'comment';

    public const KIND_RESOLVED = 'resolved';

    public const KIND_REOPENED = 'reopened';

    public const KIND_SEVERITY_CHANGED = 'severity_changed';

    public const KIND_SUPPRESSED = 'suppressed';

    public $timestamps = false;

    protected $table = 'incident_activity';

    protected $fillable = [
        'tenant_id',
        'store_id',
        'incident_id',
        'kind',
        'actor_id',
        'incident_revision',
        'sanitized_data',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'incident_revision' => 'integer',
            'sanitized_data' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }
}
