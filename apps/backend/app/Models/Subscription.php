<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    use HasUuids;

    public const STATUS_TRIAL = 'trial';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAST_DUE = 'past_due';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = ['tenant_id', 'provider', 'provider_customer_ref', 'provider_subscription_ref', 'plan_code', 'plan_version_id', 'entitlements', 'extra_store_quantity', 'faster_check_interval_minutes', 'status', 'period_start', 'period_end', 'trial_ends_at', 'past_due_since', 'cancel_at_period_end', 'version'];

    protected function casts(): array
    {
        return [
            'entitlements' => 'array',
            'extra_store_quantity' => 'integer',
            'faster_check_interval_minutes' => 'integer',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'trial_ends_at' => 'datetime',
            'past_due_since' => 'datetime',
            'cancel_at_period_end' => 'boolean',
            'version' => 'integer',
        ];
    }
}
