<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ReconciliationDirtySubject extends Model
{
    use HasUuids;

    public const TYPE_ORDER = 'order';

    public const TYPE_STORE_UNMATCHED_PAYMENTS = 'store_unmatched_payments';

    public const REASON_ORDER_EVENT = 'order_event';

    public const REASON_REFUND_EVENT = 'refund_event';

    public const REASON_PAYMENT_EVENT = 'payment_event';

    public const REASON_ALLOCATION_CHANGED = 'allocation_changed';

    public const REASON_GRACE_DEADLINE = 'grace_deadline';

    public const REASON_NIGHTLY_SWEEP = 'nightly_sweep';

    public const REASON_PROVIDER_COVERAGE_CHANGED = 'provider_coverage_changed';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'store_id',
        'subject_type',
        'subject_id',
        'reason',
        'mark_version',
        'first_marked_at',
        'last_marked_at',
        'due_at',
        'lease_until',
        'attempts',
        'error_code',
    ];

    protected function casts(): array
    {
        return [
            'mark_version' => 'integer',
            'first_marked_at' => 'datetime',
            'last_marked_at' => 'datetime',
            'due_at' => 'datetime',
            'lease_until' => 'datetime',
            'attempts' => 'integer',
        ];
    }
}
