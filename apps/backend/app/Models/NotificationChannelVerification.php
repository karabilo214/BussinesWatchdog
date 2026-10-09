<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class NotificationChannelVerification extends Model
{
    use HasUuids;

    public const TTL_MINUTES = 15;

    public const MAX_ATTEMPTS = 5;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'channel_id',
        'code_hash',
        'attempts',
        'expires_at',
        'consumed_at',
        'created_at',
    ];

    protected $hidden = [
        'code_hash',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
