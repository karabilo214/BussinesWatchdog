<?php

namespace App\Support\Notifications\Channels;

final class SendResult
{
    public const OUTCOME_SENT = 'sent';

    public const OUTCOME_TRANSIENT_FAILURE = 'transient_failure';

    public const OUTCOME_PERMANENT_FAILURE = 'permanent_failure';

    public const OUTCOME_UNCERTAIN = 'uncertain';

    private function __construct(
        public readonly string $outcome,
        public readonly ?string $providerMessageId,
        public readonly ?string $errorCode,
        public readonly ?int $retryAfterSeconds,
    ) {}

    public static function sent(?string $providerMessageId): self
    {
        return new self(self::OUTCOME_SENT, $providerMessageId, null, null);
    }

    public static function transientFailure(string $errorCode, ?int $retryAfterSeconds = null): self
    {
        return new self(self::OUTCOME_TRANSIENT_FAILURE, null, $errorCode, $retryAfterSeconds);
    }

    public static function permanentFailure(string $errorCode): self
    {
        return new self(self::OUTCOME_PERMANENT_FAILURE, null, $errorCode, null);
    }

    public static function uncertain(string $errorCode): self
    {
        return new self(self::OUTCOME_UNCERTAIN, null, $errorCode, null);
    }
}
