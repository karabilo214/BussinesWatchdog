<?php

namespace App\Support\Notifications\Channels;

use App\Support\Notifications\RenderedNotification;

interface NotificationChannelSender
{
    public function kind(): string;

    public function send(string $destination, RenderedNotification $message): SendResult;
}
