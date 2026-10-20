<?php

namespace App\Support\Privacy;

use RuntimeException;

class DeletionRejected extends RuntimeException
{
    public function __construct(public readonly string $reasonCode, public readonly int $status = 409)
    {
        parent::__construct($reasonCode);
    }
}
