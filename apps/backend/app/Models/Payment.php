<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'store_id',
        'integration_id',
        'external_id',
        'intent_ref',
        'charge_ref',
        'mode',
        'currency',
        'currency_exponent',
        'status',
        'source_authority',
        'source_updated_at',
        'metadata',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'currency_exponent' => 'integer',
            'source_updated_at' => 'datetime',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(FinancialTransaction::class);
    }
}
