<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Integration extends Model
{
    use HasUuids;

    public const SOURCE_STORE_REPORTED = 'store_reported';

    public const SOURCE_INDEPENDENT_PROVIDER = 'independent_provider';

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DEGRADED = 'degraded';

    public const STATUS_REVOKED = 'revoked';

    public const STATUS_DISABLED = 'disabled';

    protected $fillable = [
        'tenant_id',
        'store_id',
        'provider',
        'external_account_id',
        'install_id',
        'mode',
        'source_authority',
        'status',
        'capabilities',
        'api_version',
        'connector_version',
        'last_heartbeat_at',
        'last_successful_sync_at',
        'health',
    ];

    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'health' => 'array',
            'last_heartbeat_at' => 'datetime',
            'last_successful_sync_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(IntegrationCredential::class);
    }
}
