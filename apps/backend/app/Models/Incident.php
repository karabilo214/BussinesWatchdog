<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Incident extends Model
{
    use HasUuids;

    public const STATE_OPEN = 'open';

    public const STATE_ACKNOWLEDGED = 'acknowledged';

    public const STATE_RESOLVED = 'resolved';

    public const SEVERITY_INFO = 'info';

    public const SEVERITY_WARNING = 'warning';

    public const SEVERITY_CRITICAL = 'critical';

    public const ACTIVE_STATES = [self::STATE_OPEN, self::STATE_ACKNOWLEDGED];

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'store_id',
        'family',
        'component',
        'fingerprint',
        'state',
        'severity',
        'title_code',
        'currency',
        'verified_discrepancy_minor',
        'first_seen_at',
        'last_seen_at',
        'last_good_at',
        'first_bad_at',
        'acknowledged_by',
        'acknowledged_at',
        'resolved_at',
        'resolution_reason',
        'revision',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'verified_discrepancy_minor' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_good_at' => 'datetime',
            'first_bad_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'resolved_at' => 'datetime',
            'revision' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function activity(): HasMany
    {
        return $this->hasMany(IncidentActivity::class)->orderBy('created_at');
    }

    public function suppressions(): HasMany
    {
        return $this->hasMany(Suppression::class);
    }

    public function isActiveSuppressed(): bool
    {
        return $this->suppressions()
            ->whereNull('revoked_at')
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>', now())
            ->exists();
    }
}
