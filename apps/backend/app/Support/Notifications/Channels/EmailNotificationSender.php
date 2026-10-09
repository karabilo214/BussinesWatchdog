<?php

namespace App\Support\Notifications\Channels;

use App\Mail\NotificationMessageMail;
use App\Models\NotificationChannel;
use App\Support\Notifications\RenderedNotification;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class EmailNotificationSender implements NotificationChannelSender
{
    public function kind(): string
    {
        return NotificationChannel::KIND_EMAIL;
    }

    public function send(string $destination, RenderedNotification $message): SendResult
    {
        try {
            $sent = Mail::to($destination)->send(new NotificationMessageMail($message->subject, $message->body));
        } catch (TransportExceptionInterface $exception) {
            if (str_contains(mb_strtolower($exception->getMessage()), 'timed out')
                || str_contains(mb_strtolower($exception->getMessage()), 'timeout')) {
                return SendResult::uncertain('email_transport_timeout');
            }

            return SendResult::transientFailure('email_transport_error');
        }

        return SendResult::sent($sent?->getMessageId());
    }
}
