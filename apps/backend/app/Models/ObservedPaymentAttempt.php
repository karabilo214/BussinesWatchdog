<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One real payment attempt the service derived itself from a store's order reads (ADR 0056), for connectors that do
 * not report attempts: the outcome feeds the same 5-minute windows as the plugin's aggregates.
 */
class ObservedPaymentAttempt extends Model
{
    use HasUuids;

    public const OUTCOME_PENDING = 'pending';

    public const OUTCOME_PAID = 'paid';

    public const OUTCOME_ON_HOLD = 'on_hold';

    public const OUTCOME_FAILED = 'failed';

    public const OUTCOME_PENDING_STUCK = 'pending_stuck';

    public const OUTCOME_LATE_SUCCESS = 'late_success';

    public const SUCCESSES = [self::OUTCOME_PAID, self::OUTCOME_ON_HOLD, self::OUTCOME_LATE_SUCCESS];

    public const FAILURES = [self::OUTCOME_FAILED, self::OUTCOME_PENDING_STUCK];

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'store_id',
        'integration_id',
        'transaction_ref',
        'order_ref',
        'payment_method',
        'attempted_at',
        'outcome',
        'failure_class',
        'outcome_at',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'attempted_at' => 'datetime',
            'outcome_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
