<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CheckStep extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'store_id',
        'attempt_id',
        'step_index',
        'step_code',
        'status',
        'started_at',
        'finished_at',
        'assertions',
        'network_summary',
        'error_code',
    ];

    protected function casts(): array
    {
        return [
            'step_index' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'assertions' => 'array',
            'network_summary' => 'array',
        ];
    }
}
