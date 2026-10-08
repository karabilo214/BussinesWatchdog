<?php

namespace App\Support\Ingest;

class EventValidationResult
{
    public const ERROR_SCHEMA_INVALID = 'schema_invalid';

    public const ERROR_SCHEMA_UNSUPPORTED = 'schema_unsupported';

    public const ERROR_CLOCK_SKEW = 'clock_skew';

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
