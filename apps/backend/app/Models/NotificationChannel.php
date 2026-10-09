<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NotificationChannel extends Model
{
    use HasUuids;

    public const KIND_EMAIL = 'email';

    public const KIND_TELEGRAM = 'telegram';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'kind',
        'destination_ciphertext',
        'key_version',
        'label',
        'enabled',
        'verified_at',
        'preferences',
        'health',
        'created_at',
        'updated_at',
    ];

    protected $hidden = [
        'destination_ciphertext',
    ];

    protected function casts(): array
    {
        return [
            'key_version' => 'integer',
            'enabled' => 'boolean',
            'verified_at' => 'datetime',
            'preferences' => 'array',
            'health' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(NotificationDelivery::class, 'channel_id');
    }

    public function isDeliverable(): bool
    {
        return $this->enabled && $this->verified_at !== null;
    }
}
