<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingPlanChange extends Model
{
    use HasUuids;

    public const KIND_IMMEDIATE = 'immediate';

    public const KIND_NEXT_PERIOD = 'next_period';

    public const STATUS_PREVIEWED = 'previewed';

    public const STATUS_APPLYING = 'applying';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_FAILED = 'failed';

    public const STATUS_UNKNOWN = 'unknown';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = ['tenant_id', 'contract_id', 'actor_user_id', 'kind', 'status', 'from_price_id', 'from_extra_store_quantity', 'to_plan_version_id', 'to_price_id', 'to_extra_store_price_id', 'to_extra_store_quantity', 'from_faster_checks', 'to_faster_checks', 'to_faster_checks_price_id', 'retained_store_ids', 'currency', 'currency_exponent', 'due_now_minor', 'next_amount_before_minor', 'next_amount_minor', 'contract_version', 'preview_expires_at', 'effective_at', 'idempotency_key', 'error_code', 'applied_at', 'discounts_settled_at'];

    protected function casts(): array
    {
        return [
            'from_extra_store_quantity' => 'integer',
            'to_extra_store_quantity' => 'integer',
            'from_faster_checks' => 'boolean',
            'to_faster_checks' => 'boolean',
            'retained_store_ids' => 'array',
            'currency_exponent' => 'integer',
            'due_now_minor' => 'string',
            'next_amount_before_minor' => 'string',
            'next_amount_minor' => 'string',
            'contract_version' => 'integer',
            'preview_expires_at' => 'datetime',
            'effective_at' => 'datetime',
            'applied_at' => 'datetime',
            'discounts_settled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<BillingPrice, $this>
     */
    public function fromPrice(): BelongsTo
    {
        return $this->belongsTo(BillingPrice::class, 'from_price_id');
    }

    /**
     * @return BelongsTo<BillingPrice, $this>
     */
    public function toPrice(): BelongsTo
    {
        return $this->belongsTo(BillingPrice::class, 'to_price_id');
    }
}
