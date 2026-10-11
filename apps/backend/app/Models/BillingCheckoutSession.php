<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class BillingCheckoutSession extends Model
{
    use HasUuids;

    public const STATUS_CREATING = 'creating';

    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_UNKNOWN = 'unknown';

    protected $fillable = ['tenant_id', 'actor_user_id', 'contract_id', 'plan_version_id', 'price_id', 'extra_store_price_id', 'extra_store_quantity', 'provider', 'provider_account_ref', 'provider_session_ref', 'mode', 'status', 'error_code', 'idempotency_key', 'request_hash', 'expires_at', 'confirmed_at'];

    protected function casts(): array
    {
        return ['extra_store_quantity' => 'integer', 'expires_at' => 'datetime', 'confirmed_at' => 'datetime'];
    }
}
