<?php

namespace App\Support\Providers\Shopware;

use RuntimeException;

class ShopwareRequestFailed extends RuntimeException
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
        return in_array($this->reasonCode, ['shopware_key_rejected', 'shopware_order_read_missing'], true);
    }
}
