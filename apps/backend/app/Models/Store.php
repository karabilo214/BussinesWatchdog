<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Store extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id',
        'name',
        'base_url',
        'platform',
        'timezone',
        'locale',
        'default_currency',
        'status',
        'verified_at',
        'browser_enabled',
        'telemetry_enabled',
        'config_version',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'browser_enabled' => 'boolean',
            'telemetry_enabled' => 'boolean',
            'config_version' => 'integer',
            'settings' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
