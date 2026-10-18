<?php

namespace App\Support\Platform;

use RuntimeException;

class ReminderRejected extends RuntimeException
{
    public function __construct(public readonly string $reasonCode, public readonly int $status = 409, public readonly ?string $locale = null)
    {
        parent::__construct($reasonCode);
    }
}
