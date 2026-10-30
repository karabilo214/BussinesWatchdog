<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
            'capacity_paused_at' => 'datetime',
            'capacity_last_paused_from' => 'datetime',
            'capacity_last_resumed_at' => 'datetime',
            'order_volume_average' => 'integer',
            'order_volume_checked_at' => 'datetime',
            'order_volume_over_since' => 'datetime',
            'order_volume_notified_at' => 'datetime',
            'browser_enabled' => 'boolean',
            'telemetry_enabled' => 'boolean',
            'config_version' => 'integer',
            'settings' => 'array',
            'sales_baseline_exclusions' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function verifications(): HasMany
    {
        return $this->hasMany(StoreVerification::class);
    }

    public function integrations(): HasMany
    {
        return $this->hasMany(Integration::class);
    }

    public function pairingCodes(): HasMany
    {
        return $this->hasMany(PairingCode::class);
    }
}
