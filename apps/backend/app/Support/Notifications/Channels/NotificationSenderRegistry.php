<?php

namespace App\Support\Notifications\Channels;

class NotificationSenderRegistry
{
    /**
     * @var array<string, NotificationChannelSender>
     */
    private array $senders = [];

    /**
     * @param  iterable<NotificationChannelSender>  $senders
     */
    public function __construct(iterable $senders)
    {
        foreach ($senders as $sender) {
            $this->senders[$sender->kind()] = $sender;
        }
    }

    public function for(string $kind): ?NotificationChannelSender
    {
        return $this->senders[$kind] ?? null;
    }
}
