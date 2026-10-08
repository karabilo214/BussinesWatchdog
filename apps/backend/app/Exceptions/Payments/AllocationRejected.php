<?php

namespace App\Exceptions\Payments;

use RuntimeException;

class AllocationRejected extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
    ) {
        parent::__construct($reasonCode);
    }
}
