<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CheckAttempt extends Model
{
    use HasUuids;

    public const STATUS_RUNNING = 'running';

    public const STATUS_EXPIRED = 'expired';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'store_id',
        'run_id',
        'attempt_number',
        'worker_id',
        'fencing_token',
        'lease_token_hash',
        'lease_until',
        'absolute_deadline_at',
        'last_heartbeat_at',
        'status',
        'browser_version',
        'location',
        'started_at',
        'finished_at',
        'result_hash',
        'error_code',
        'sanitized_error',
    ];

    protected $hidden = ['lease_token_hash'];

    protected function casts(): array
    {
        return [
            'attempt_number' => 'integer',
            'fencing_token' => 'integer',
            'lease_until' => 'datetime',
            'absolute_deadline_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'sanitized_error' => 'array',
        ];
    }

    public function steps(): HasMany
    {
        return $this->hasMany(CheckStep::class, 'attempt_id')->orderBy('step_index');
    }
}
