<?php

namespace App\Support\Providers\Stripe;

use RuntimeException;

class StripeRequestFailed extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        public readonly int $status,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($reasonCode);
    }

    /** The key itself is no longer usable: stop calling Stripe until the owner reconnects. */
    public function keyUnusable(): bool
    {
        return $this->reasonCode === 'stripe_key_rejected';
    }
}
