<?php

namespace App\Support\Billing;

use RuntimeException;

class PlanAdminRejected extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        public readonly int $status = 422,
    ) {
        parent::__construct($reasonCode);
    }
}
