<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Artifact extends Model
{
    use HasUuids;

    public const KIND_SCREENSHOT = 'screenshot';

    public const STATE_READY = 'ready';

    public const STATE_REJECTED = 'rejected';

    public const STATE_DELETED = 'deleted';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'store_id',
        'attempt_id',
        'kind',
        'object_key',
        'content_type',
        'size_bytes',
        'sha256',
        'redaction_version',
        'state',
        'expires_at',
        'deleted_at',
        'created_at',
    ];

    protected $hidden = ['object_key'];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'expires_at' => 'datetime',
            'deleted_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
