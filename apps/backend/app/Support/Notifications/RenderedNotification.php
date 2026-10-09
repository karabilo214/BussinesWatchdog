<?php

namespace App\Support\Notifications;

final class RenderedNotification
{
    public function __construct(
        public readonly string $subject,
        public readonly string $body,
    ) {}
}
