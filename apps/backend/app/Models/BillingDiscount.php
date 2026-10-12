<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class BillingDiscount extends Model
{
    use HasUuids;

    public const KIND_PERCENTAGE = 'percentage';

    public const KIND_FLAT = 'flat';

    public const AUDIENCE_CODE = 'code';

    public const AUDIENCE_TENANT = 'tenant';

    public const AUDIENCE_AUTOMATIC = 'automatic';

    public const DURATION_ONCE = 'once';

    public const DURATION_PERIODS = 'periods';

    public const DURATION_FOREVER = 'forever';

    public const STATE_ACTIVE = 'active';

    public const STATE_ARCHIVED = 'archived';

    protected $fillable = [
        'provider', 'provider_account_ref', 'mode', 'provider_discount_ref', 'name', 'label', 'kind', 'percent_bps', 'amount_minor', 'currency', 'currency_exponent',
        'audience', 'code', 'duration', 'duration_periods', 'usage_limit', 'plan_ids', 'starts_at', 'expires_at', 'state', 'created_by', 'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'label' => 'array',
            'plan_ids' => 'array',
            'percent_bps' => 'integer',
            'amount_minor' => 'integer',
            'currency_exponent' => 'integer',
            'duration_periods' => 'integer',
            'usage_limit' => 'integer',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'archived_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'billing_discount_tenants', 'discount_id', 'tenant_id');
    }
}
