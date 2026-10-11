<?php

namespace App\Support\Network;

use RuntimeException;

class StoreOriginFailure extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        public readonly int $httpStatus = 0,
    ) {
        parent::__construct($reasonCode);
    }
}
