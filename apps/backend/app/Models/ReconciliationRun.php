<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReconciliationRun extends Model
{
    use HasUuids;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'store_id',
        'status',
        'algorithm_version',
        'config_version',
        'currency',
        'scope',
        'coverage_snapshot',
        'counters',
        'started_at',
        'finished_at',
        'error_code',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'config_version' => 'integer',
            'scope' => 'array',
            'coverage_snapshot' => 'array',
            'counters' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function findings(): HasMany
    {
        return $this->hasMany(ReconciliationFinding::class, 'run_id');
    }
}
