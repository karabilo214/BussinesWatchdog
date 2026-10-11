<?php

namespace App\Support\Notifications\Channels;

use App\Models\NotificationChannel;
use App\Support\Notifications\RenderedNotification;
use Illuminate\Support\Facades\Http;

class TelegramNotificationSender implements NotificationChannelSender
{
    public function kind(): string
    {
        return NotificationChannel::KIND_TELEGRAM;
    }

    public function send(string $destination, RenderedNotification $message): SendResult
    {
        $token = (string) config('services.telegram.bot_token');

        if ($token === '') {
            return SendResult::permanentFailure('telegram_bot_not_configured');
        }

        try {
            $payload = json_decode($destination, true, 8, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return SendResult::permanentFailure('telegram_destination_invalid');
        }

        $chatId = (string) ($payload['chat_id'] ?? '');

        if ($chatId === '') {
            return SendResult::permanentFailure('telegram_destination_invalid');
        }

        try {
            $response = Http::timeout(5)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $message->subject."\n\n".$message->body,
                'disable_web_page_preview' => true,
            ]);
        } catch (\Throwable) {
            return SendResult::uncertain('telegram_transport_timeout');
        }

        if ($response->successful()) {
            $messageId = $response->json('result.message_id');

            return SendResult::sent($messageId === null ? null : (string) $messageId);
        }

        if ($response->status() === 429) {
            return SendResult::transientFailure('telegram_rate_limited', (int) $response->json('parameters.retry_after', 60));
        }

        if ($response->status() >= 500) {
            return SendResult::transientFailure('telegram_transport_error');
        }

        return SendResult::permanentFailure('telegram_rejected');
    }
}
