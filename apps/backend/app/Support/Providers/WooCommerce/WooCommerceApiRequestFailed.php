<?php

namespace App\Support\Providers\WooCommerce;

use RuntimeException;

class WooCommerceApiRequestFailed extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        public readonly int $httpStatus = 0,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($reasonCode);
    }

    public function credentialsUnusable(): bool
    {
        return in_array($this->reasonCode, ['woocommerce_key_rejected', 'woocommerce_key_permission_missing'], true);
    }
}
