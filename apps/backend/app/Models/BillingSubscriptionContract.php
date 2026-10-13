<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class BillingSubscriptionContract extends Model
{
    use HasUuids;

    public const STATE_TRIAL = 'trial';

    public const STATE_ACTIVE = 'active';

    public const STATE_PAST_DUE = 'past_due';

    public const STATE_UNPAID = 'unpaid';

    public const STATE_CANCELLED = 'cancelled';

    public const STATE_UNKNOWN = 'unknown';

    protected $fillable = ['tenant_id', 'customer_id', 'plan_version_id', 'price_id', 'provider', 'provider_account_ref', 'provider_subscription_ref', 'mode', 'normalized_state', 'provider_state', 'currency', 'extra_store_quantity', 'faster_checks', 'entitlement_snapshot', 'period_start', 'period_end', 'paid_until', 'cancel_at_period_end', 'next_charge_at', 'problem_code', 'source_updated_at', 'version'];

    protected function casts(): array
    {
        return [
            'extra_store_quantity' => 'integer',
            'faster_checks' => 'boolean',
            'entitlement_snapshot' => 'array',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'paid_until' => 'datetime',
            'cancel_at_period_end' => 'boolean',
            'next_charge_at' => 'datetime',
            'source_updated_at' => 'datetime',
            'version' => 'integer',
        ];
    }
}
