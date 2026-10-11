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
        'plan_not_available' => 422,
        'email_not_verified' => 403,
    ];

    public function __construct(public readonly string $reasonCode)
    {
        parent::__construct($reasonCode);
    }

    public function status(): int
    {
        return self::STATUS[$this->reasonCode] ?? 422;
    }
}
