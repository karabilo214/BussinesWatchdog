<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentAllocation extends Model
{
    use HasUuids;

    public const STRATEGY_EXACT_REFERENCE = 'exact_reference';

    public const STRATEGY_VERIFIED_METADATA = 'verified_metadata';

    /** Refund allocations only: the single same-amount pair inside an exactly matched order (ADR 0022). */
    public const STRATEGY_UNIQUE_AMOUNT = 'unique_amount';

    public const STRATEGY_MANUAL = 'manual';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'store_id',
        'payment_id',
        'capture_transaction_id',
        'order_id',
        'currency',
        'amount_minor',
        'strategy',
        'evidence',
        'created_by',
        'revoked_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'evidence' => 'array',
            'revoked_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function captureTransaction(): BelongsTo
    {
        return $this->belongsTo(FinancialTransaction::class, 'capture_transaction_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function refundAllocations(): HasMany
    {
        return $this->hasMany(RefundAllocation::class);
    }
}
