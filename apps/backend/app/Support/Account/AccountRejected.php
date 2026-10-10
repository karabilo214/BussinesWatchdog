<?php

namespace App\Support\Account;

use RuntimeException;

class AccountRejected extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        public readonly int $status = 422,
    ) {
        parent::__construct($reasonCode);
    }
}
