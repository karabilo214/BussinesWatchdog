<?php

namespace App\Support\Network;

final class SafeFetchResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly ?string $body,
        public readonly ?string $errorCode,
    ) {}

    public static function ok(string $body): self
    {
        return new self(true, $body, null);
    }

    public static function failed(string $errorCode): self
    {
        return new self(false, null, $errorCode);
    }
}
