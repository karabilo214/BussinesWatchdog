<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class IdempotencyKey extends Model
{
    use HasUuids;

    public const TTL_HOURS = 24;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'actor_user_id',
        'route',
        'idempotency_key',
        'request_hash',
        'response_status',
        'response_body',
        'created_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'response_status' => 'integer',
            'created_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
