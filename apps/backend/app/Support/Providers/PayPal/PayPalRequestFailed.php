<?php

namespace App\Support\Providers\PayPal;

use RuntimeException;

class PayPalRequestFailed extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        public readonly int $status,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($reasonCode);
    }

    /** The credentials themselves are no longer usable: stop calling PayPal until the owner reconnects. */
    public function credentialsUnusable(): bool
    {
        return $this->reasonCode === 'paypal_credentials_rejected';
    }
}
