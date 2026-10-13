<?php

namespace App\Support\Platform;

use RuntimeException;

class StaffRejected extends RuntimeException
{
    public function __construct(public readonly string $reasonCode, public readonly int $status = 422)
    {
        parent::__construct($reasonCode);
    }
}
