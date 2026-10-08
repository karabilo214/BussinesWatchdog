<?php

namespace App\Support\Ingest;

class EventValidationResult
{
    private function __construct(
        public readonly bool $valid,
        public readonly ?string $errorCode = null,
        public readonly bool $quarantinable = false,
    ) {
    }

    public static function ok(): self
    {
        return new self(true);
    }

    public static function invalid(string $errorCode, bool $quarantinable = false): self
    {
        return new self(false, $errorCode, $quarantinable);
    }
}
