<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Signal extends Model
{
    use HasUuids;

    public const SEVERITY_INFO = 'info';

    public const SEVERITY_WARNING = 'warning';

    public const SEVERITY_CRITICAL = 'critical';

    public const CONFIDENCE_OBSERVED = 'observed';

    public const CONFIDENCE_CORROBORATED = 'corroborated';

    public const CONFIDENCE_INFERRED = 'inferred';

    public const CONFIDENCE_UNKNOWN = 'unknown';

    public const TYPE_RECONCILIATION_FINDING = 'reconciliation_finding';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'store_id',
        'signal_type',
        'family',
        'component',
        'dedupe_key',
        'severity',
        'confidence',
        'rule_version',
        'config_version',
        'currency',
        'finding_id',
        'check_run_id',
        'baseline_id',
        'observed_start',
        'observed_end',
        'evidence',
        'data_quality',
        'detected_at',
    ];

    protected function casts(): array
    {
        return [
            'config_version' => 'integer',
            'observed_start' => 'datetime',
            'observed_end' => 'datetime',
            'evidence' => 'array',
            'data_quality' => 'array',
            'detected_at' => 'datetime',
        ];
    }

    public function finding(): BelongsTo
    {
        return $this->belongsTo(ReconciliationFinding::class, 'finding_id');
    }
}
