<?php

namespace App\Support\Billing;

use RuntimeException;

class BillingCheckoutRejected extends RuntimeException
{
    private const STATUS = [
        'billing_not_configured' => 503,
        'price_not_available' => 422,
        'extra_stores_out_of_range' => 422,
        'subscription_exists' => 409,
        'idempotency_conflict' => 409,
        'trial_not_available' => 409,
        'trial_store_already_used' => 409,
        'plan_not_available' => 422,
        'email_not_verified' => 403,
        'discount_code_invalid' => 422,
        'discount_expired' => 422,
        'discount_not_applicable' => 422,
        'discount_used_up' => 409,
        'no_active_subscription' => 409,
        'subscription_past_due' => 409,
        'subscription_unknown' => 409,
        'plan_unchanged' => 422,
        'scheduled_change_pending' => 409,
        'currency_change_unsupported' => 422,
        'interval_change_unsupported' => 422,
        'retained_stores_required' => 422,
        'retained_stores_invalid' => 422,
        'preview_not_found' => 404,
        'preview_expired' => 409,
        'preview_stale' => 409,
        'preview_used' => 409,
        'no_scheduled_change' => 409,
        'cancel_not_scheduled' => 409,
        'version_conflict' => 409,
        'provider_unavailable' => 502,
        'provider_outcome_unknown' => 502,
        'payment_failed' => 402,
        'plan_change_rejected' => 422,
    ];

    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(public readonly string $reasonCode, public readonly array $details = [])
    {
        parent::__construct($reasonCode);
    }

    public function status(): int
    {
        return self::STATUS[$this->reasonCode] ?? 422;
    }
}
