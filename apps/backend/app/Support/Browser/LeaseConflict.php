<?php

namespace App\Support\Browser;

use RuntimeException;

class LeaseConflict extends RuntimeException
{
    public function __construct(public readonly string $reasonCode, public readonly int $httpStatus = 409)
    {
        parent::__construct($reasonCode);
    }
}
