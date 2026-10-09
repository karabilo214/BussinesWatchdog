<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CheckScenario extends Model
{
    use HasUuids;

    public const MODE_PAYMENT_FORM = 'payment_form';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'store_id',
        'name',
        'mode',
        'version',
        'enabled',
        'adapter_version',
        'definition',
        'product_external_id',
        'interval_seconds',
        'next_due_at',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'enabled' => 'boolean',
            'definition' => 'array',
            'interval_seconds' => 'integer',
            'next_due_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
