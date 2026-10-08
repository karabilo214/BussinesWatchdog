<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReconciliationFinding extends Model
{
    use HasUuids;

    public const STATUS_OK = 'ok';

    public const STATUS_PENDING = 'pending';

    public const STATUS_MISMATCH = 'mismatch';

    public const STATUS_UNSUPPORTED = 'unsupported';

    public const STATUS_UNKNOWN = 'unknown';

    public const RULE_CAPTURE_MISSING = 'MONEY_CAPTURE_MISSING';

    public const RULE_CAPTURE_AMOUNT = 'MONEY_CAPTURE_AMOUNT';

    public const RULE_REFUND_MISSING = 'MONEY_REFUND_MISSING';

    public const RULE_REFUND_EXTRA = 'MONEY_REFUND_EXTRA';

    public const RULE_PAYMENT_WITHOUT_ORDER = 'MONEY_PAYMENT_WITHOUT_ORDER';

    public const RULE_MULTIPLE_CAPTURES = 'MONEY_MULTIPLE_CAPTURES';

    public const RULE_CURRENCY_MISMATCH = 'MONEY_CURRENCY_MISMATCH';

    public const RULE_ORDER_CHANGED = 'MONEY_ORDER_CHANGED';

    public const RULE_UNSUPPORTED = 'MONEY_UNSUPPORTED';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'store_id',
        'run_id',
        'order_id',
        'payment_id',
        'rule_code',
        'status',
        'reason_code',
        'currency',
        'currency_exponent',
        'expected_minor',
        'actual_minor',
        'difference_minor',
        'gross_minor',
        'captured_minor',
        'refund_expected_minor',
        'refund_actual_minor',
        'evidence',
        'config_snapshot',
        'evaluated_at',
    ];

    protected function casts(): array
    {
        return [
            'currency_exponent' => 'integer',
            'expected_minor' => 'integer',
            'actual_minor' => 'integer',
            'difference_minor' => 'integer',
            'gross_minor' => 'integer',
            'captured_minor' => 'integer',
            'refund_expected_minor' => 'integer',
            'refund_actual_minor' => 'integer',
            'evidence' => 'array',
            'config_snapshot' => 'array',
            'evaluated_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ReconciliationRun::class, 'run_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
