<?php

namespace App\Support\Site;

use RuntimeException;

class SiteRejected extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        public readonly int $status = 422,
    ) {
        parent::__construct($reasonCode);
    }
}
