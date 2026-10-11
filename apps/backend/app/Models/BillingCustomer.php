<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class BillingCustomer extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['tenant_id', 'provider', 'provider_account_ref', 'provider_customer_ref', 'mode', 'source_updated_at', 'created_at'];

    protected function casts(): array
    {
        return ['source_updated_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
