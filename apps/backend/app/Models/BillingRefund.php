<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class BillingRefund extends Model
{
    use HasUuids;

    public const STATUS_REQUESTED = 'requested';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_SUBMITTING = 'submitting';

    public const STATUS_PENDING = 'pending';

    public const STATUS_REFUNDED = 'refunded';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_FAILED = 'failed';

    public const STATUS_UNKNOWN = 'unknown';

    /** Statuses that hold part of the payment (a new request may not exceed what is left). */
    public const RESERVING = [self::STATUS_REQUESTED, self::STATUS_SUBMITTING, self::STATUS_PENDING, self::STATUS_REFUNDED, self::STATUS_UNKNOWN];

    protected $fillable = [
        'tenant_id', 'transaction_id', 'mode', 'kind', 'amount_minor', 'currency', 'currency_exponent', 'reason', 'cancel_subscription', 'status',
        'provider_adjustment_ref', 'provider_status', 'tax_minor', 'error_code', 'requested_by', 'decided_by', 'decision_reason', 'decided_at', 'source_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'currency_exponent' => 'integer',
            'tax_minor' => 'integer',
            'cancel_subscription' => 'boolean',
            'decided_at' => 'datetime',
            'source_updated_at' => 'datetime',
        ];
    }
}
