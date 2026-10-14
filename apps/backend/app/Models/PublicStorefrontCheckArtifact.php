<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublicStorefrontCheckArtifact extends Model
{
    use HasUuids;

    public const KIND_SCREENSHOT = 'screenshot';

    public const STATE_READY = 'ready';

    public const STATE_DELETED = 'deleted';

    public $timestamps = false;

    protected $fillable = [
        'check_id',
        'kind',
        'object_key',
        'content_type',
        'size_bytes',
        'sha256',
        'redaction_version',
        'state',
        'created_at',
        'expires_at',
        'deleted_at',
    ];

    protected $hidden = ['object_key'];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'created_at' => 'datetime',
            'expires_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function check(): BelongsTo
    {
        return $this->belongsTo(PublicStorefrontCheck::class, 'check_id');
    }
}
