<?php

namespace BusinessWatchdog\WooCommerce\Http;

final class Response
{
    public int $status;

    public ?array $json;

    public ?string $errorCode;

    public ?int $retryAfterSeconds;

    public function __construct(int $status, ?array $json, ?string $errorCode, ?int $retryAfterSeconds)
    {
        $this->status = $status;
        $this->json = $json;
        $this->errorCode = $errorCode;
        $this->retryAfterSeconds = $retryAfterSeconds;
    }

    public function successful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function transportFailed(): bool
    {
        return $this->status === 0;
    }
}
