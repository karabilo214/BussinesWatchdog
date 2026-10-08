<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialTransaction extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'store_id',
        'integration_id',
        'payment_id',
        'external_operation_id',
        'kind',
        'status',
        'currency',
        'currency_exponent',
        'amount_minor',
        'occurred_at',
        'source_event_id',
        'source_authority',
        'operation_hash',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'currency_exponent' => 'integer',
            'amount_minor' => 'integer',
            'occurred_at' => 'datetime',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function sourceEvent(): BelongsTo
    {
        return $this->belongsTo(EventInbox::class, 'source_event_id');
    }
}
