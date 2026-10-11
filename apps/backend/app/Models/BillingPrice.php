<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class BillingPrice extends Model
{
    use HasUuids;

    public const KIND_BASE = 'base';

    /** Per additional store above the stores included in the plan (sold as a quantity). */
    public const KIND_EXTRA_STORE = 'extra_store';

    public $timestamps = false;

    protected $fillable = ['plan_version_id', 'provider', 'provider_account_ref', 'provider_price_ref', 'price_kind', 'mode', 'currency', 'currency_exponent', 'amount_minor', 'billing_interval', 'tax_behavior', 'state', 'created_at'];

    protected function casts(): array
    {
        return ['currency_exponent' => 'integer', 'amount_minor' => 'integer', 'created_at' => 'datetime'];
    }
}
