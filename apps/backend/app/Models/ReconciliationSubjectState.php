<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReconciliationSubjectState extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'tenant_id', 'store_id', 'subject_type', 'subject_id', 'run_id', 'result_hash', 'worst_status', 'finding_count',
        'algorithm_version', 'config_version', 'entitled', 'store_data_stale', 'evaluated_at', 'changed_at',
    ];

    protected function casts(): array
    {
        return [
            'finding_count' => 'integer',
            'config_version' => 'integer',
            'entitled' => 'boolean',
            'store_data_stale' => 'boolean',
            'evaluated_at' => 'datetime',
            'changed_at' => 'datetime',
        ];
    }
}
