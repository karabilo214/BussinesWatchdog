<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Refund extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'store_id',
        'integration_id',
        'order_id',
        'external_id',
        'source_revision',
        'currency',
        'currency_exponent',
        'amount_minor',
        'external_required',
        'provider_ref',
        'status',
        'occurred_at',
        'current_payload_hash',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'source_revision' => 'integer',
            'currency_exponent' => 'integer',
            'amount_minor' => 'integer',
            'external_required' => 'boolean',
            'occurred_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
