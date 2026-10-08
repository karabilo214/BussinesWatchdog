<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'store_id',
        'integration_id',
        'external_id',
        'display_number',
        'source_revision',
        'status',
        'gateway',
        'mode',
        'currency',
        'currency_exponent',
        'total_minor',
        'payment_expected',
        'paid_marked_at',
        'transaction_ref',
        'financial_support',
        'is_synthetic',
        'source_created_at',
        'source_updated_at',
        'deleted_at',
        'current_payload_hash',
        'metadata',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'source_revision' => 'integer',
            'currency_exponent' => 'integer',
            'total_minor' => 'integer',
            'payment_expected' => 'boolean',
            'paid_marked_at' => 'datetime',
            'is_synthetic' => 'boolean',
            'source_created_at' => 'datetime',
            'source_updated_at' => 'datetime',
            'deleted_at' => 'datetime',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(OrderRevision::class);
    }
}
