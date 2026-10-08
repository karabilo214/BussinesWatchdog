<?php

namespace App\Support\Ingest;

class EventProjectionResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly ?string $errorCode = null,
    ) {
    }

    public static function ok(): self
    {
        return new self(true);
    }

    public static function failed(string $errorCode): self
    {
        return new self(false, $errorCode);
    }
}
