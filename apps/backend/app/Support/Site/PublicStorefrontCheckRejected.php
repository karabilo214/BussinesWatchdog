<?php

namespace App\Support\Site;

use RuntimeException;

class PublicStorefrontCheckRejected extends RuntimeException
{
    public function __construct(public readonly string $reasonCode, public readonly int $httpStatus = 422)
    {
        parent::__construct($reasonCode);
    }
}
