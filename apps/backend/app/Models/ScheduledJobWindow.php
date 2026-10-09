<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ScheduledJobWindow extends Model
{
    use HasUuids;

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public $timestamps = false;

    protected $fillable = [
        'job',
        'window_key',
        'status',
        'started_at',
        'finished_at',
        'error_code',
        'result',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'result' => 'array',
        ];
    }
}
