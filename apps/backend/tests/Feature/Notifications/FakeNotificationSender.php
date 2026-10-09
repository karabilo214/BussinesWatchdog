<?php

namespace Tests\Feature\Notifications;

use App\Models\NotificationChannel;
use App\Support\Notifications\Channels\NotificationChannelSender;
use App\Support\Notifications\Channels\SendResult;
use App\Support\Notifications\RenderedNotification;

class FakeNotificationSender implements NotificationChannelSender
{
    /**
     * @var list<SendResult>
     */
    public array $queuedResults = [];

    /**
     * @var list<array{destination: string, message: RenderedNotification}>
     */
    public array $sent = [];

    public function kind(): string
    {
        return NotificationChannel::KIND_EMAIL;
    }

    public function send(string $destination, RenderedNotification $message): SendResult
    {
        $this->sent[] = ['destination' => $destination, 'message' => $message];

        return array_shift($this->queuedResults) ?? SendResult::sent('fake-'.count($this->sent));
    }
}
