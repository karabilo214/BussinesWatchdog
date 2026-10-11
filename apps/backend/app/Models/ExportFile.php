<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ExportFile extends Model
{
    use HasUuids;

    protected $table = 'exports';

    public const KIND_FINDINGS = 'findings';

    public const KIND_INCIDENTS = 'incidents';

    public const KIND_TRANSACTIONS = 'transactions';

    public const FORMAT_CSV = 'csv';

    public const STATE_PROCESSING = 'processing';

    public const STATE_READY = 'ready';

    public const STATE_FAILED = 'failed';

    public const STATE_DELETED = 'deleted';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'store_id',
        'created_by',
        'kind',
        'format',
        'filters',
        'state',
        'object_key',
        'content_type',
        'size_bytes',
        'sha256',
        'error_code',
        'expires_at',
        'completed_at',
        'deleted_at',
        'created_at',
    ];

    protected $hidden = ['object_key'];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'size_bytes' => 'integer',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
            'deleted_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
