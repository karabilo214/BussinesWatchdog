<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Shopware shop that installed the universal app (ADR 0076). It belongs to no customer until the merchant enters
 * the store's pairing code in the app; until then it reads nothing.
 */
class ShopwareAppInstallation extends Model
{
    use HasUuids;

    public const STATUS_REGISTERED = 'registered';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_BOUND = 'bound';

    public const UNBOUND_RETENTION_DAYS = 7;

    protected $fillable = [
        'shop_id',
        'shop_url',
        'app_version',
        'secrets',
        'status',
        'integration_id',
        'registered_at',
        'confirmed_at',
        'bound_at',
    ];

    protected $hidden = ['secrets'];

    protected function casts(): array
    {
        return [
            'secrets' => 'encrypted:array',
            'registered_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'bound_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Integration, $this>
     */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }
}
