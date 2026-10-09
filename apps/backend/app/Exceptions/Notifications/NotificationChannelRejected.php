<?php

namespace App\Exceptions\Notifications;

use RuntimeException;

class NotificationChannelRejected extends RuntimeException
{
    private const STATUS_BY_REASON = [
        'test_rate_limited' => 429,
        'verification_attempts_exceeded' => 429,
        'channel_already_verified' => 409,
    ];

    public function __construct(
        public readonly string $reasonCode,
    ) {
        parent::__construct($reasonCode);
    }

    public function httpStatus(): int
    {
        return self::STATUS_BY_REASON[$this->reasonCode] ?? 422;
    }
}
