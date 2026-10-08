<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RefundAllocation extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'store_id',
        'refund_id',
        'refund_transaction_id',
        'payment_allocation_id',
        'amount_minor',
        'currency',
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

    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }

    public function refundTransaction(): BelongsTo
    {
        return $this->belongsTo(FinancialTransaction::class, 'refund_transaction_id');
    }

    public function paymentAllocation(): BelongsTo
    {
        return $this->belongsTo(PaymentAllocation::class);
    }
}
