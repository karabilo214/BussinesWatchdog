<?php

namespace App\Support\Billing\Paddle;

use RuntimeException;

class PaddleRequestFailed extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        public readonly int $status,
        public readonly ?string $paddleCode = null,
    ) {
        parent::__construct($reasonCode);
    }

    /** The request may have reached Paddle (no answer, timeout, server error): its side effect is unknown. */
    public function outcomeUnknown(): bool
    {
        return $this->status === 0 || $this->status >= 500;
    }
}
