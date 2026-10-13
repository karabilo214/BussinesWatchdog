<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class BillingTransaction extends Model
{
    use HasUuids;

    public const STATUSES = ['draft', 'ready', 'billed', 'paid', 'completed', 'canceled', 'past_due'];

    /** Statuses with an invoice document at the provider. */
    public const INVOICED = ['billed', 'paid', 'completed', 'past_due'];

    protected $fillable = [
        'tenant_id', 'contract_id', 'provider', 'provider_account_ref', 'mode', 'provider_transaction_ref', 'provider_subscription_ref', 'status', 'origin',
        'invoice_number', 'currency', 'currency_exponent', 'subtotal_minor', 'discount_minor', 'tax_minor', 'total_minor', 'fee_minor', 'earnings_minor',
        'period_start', 'period_end', 'billed_at', 'provider_created_at', 'source_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'currency_exponent' => 'integer',
            'subtotal_minor' => 'integer',
            'discount_minor' => 'integer',
            'tax_minor' => 'integer',
            'total_minor' => 'integer',
            'fee_minor' => 'integer',
            'earnings_minor' => 'integer',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'billed_at' => 'datetime',
            'provider_created_at' => 'datetime',
            'source_updated_at' => 'datetime',
        ];
    }
}
