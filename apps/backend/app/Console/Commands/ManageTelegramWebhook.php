<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ManageTelegramWebhook extends Command
{
    protected $signature = 'telegram:webhook
        {action=set : set, info, or delete}
        {--url= : Absolute HTTPS webhook URL; defaults to TELEGRAM_WEBHOOK_URL or APP_URL + /api/v1/webhooks/telegram/updates}
        {--secret= : Secret token for X-Telegram-Bot-Api-Secret-Token; defaults to TELEGRAM_WEBHOOK_SECRET}
        {--drop-pending-updates : Ask Telegram to drop pending updates while changing the webhook}';

    protected $description = 'Register, inspect, or delete the Telegram Bot API webhook for notification channel binding.';

    public function handle(): int
    {
        $action = (string) $this->argument('action');

        return match ($action) {
            'set' => $this->setWebhook(),
            'info' => $this->showInfo(),
            'delete' => $this->deleteWebhook(),
            default => $this->failWith('Unsupported action. Use set, info, or delete.'),
        };
    }

    private function setWebhook(): int
    {
        $token = $this->botToken();
        $secret = $this->webhookSecret();
        $url = $this->webhookUrl();

        if ($token === null || $secret === null || $url === null) {
            return self::FAILURE;
        }

        $response = Http::acceptJson()
            ->asJson()
            ->timeout(10)
            ->post($this->telegramApiUrl($token, 'setWebhook'), [
                'url' => $url,
                'secret_token' => $secret,
                'allowed_updates' => ['message', 'channel_post'],
                'drop_pending_updates' => (bool) $this->option('drop-pending-updates'),
            ]);

        if (! $response->ok() || $response->json('ok') !== true) {
            return $this->failWithTelegramResponse('Telegram webhook registration failed.', $response->json());
        }

        $this->components->info('Telegram webhook registered.');
        $this->line('url='.$url);
        $this->line('allowed_updates=message,channel_post');

        return self::SUCCESS;
    }

    private function showInfo(): int
    {
        $token = $this->botToken();

        if ($token === null) {
            return self::FAILURE;
        }

        $response = Http::acceptJson()
            ->timeout(10)
            ->get($this->telegramApiUrl($token, 'getWebhookInfo'));

        if (! $response->ok() || $response->json('ok') !== true) {
            return $this->failWithTelegramResponse('Telegram webhook info lookup failed.', $response->json());
        }

        $result = $response->json('result') ?? [];
        $this->components->info('Telegram webhook info.');
        $this->line('url='.(string) ($result['url'] ?? ''));
        $this->line('pending_update_count='.(string) ($result['pending_update_count'] ?? 0));

        if (isset($result['last_error_message'])) {
            $this->line('last_error_message='.(string) $result['last_error_message']);
        }

        return self::SUCCESS;
    }

    private function deleteWebhook(): int
    {
        $token = $this->botToken();

        if ($token === null) {
            return self::FAILURE;
        }

        $response = Http::acceptJson()
            ->asJson()
            ->timeout(10)
            ->post($this->telegramApiUrl($token, 'deleteWebhook'), [
                'drop_pending_updates' => (bool) $this->option('drop-pending-updates'),
            ]);

        if (! $response->ok() || $response->json('ok') !== true) {
            return $this->failWithTelegramResponse('Telegram webhook deletion failed.', $response->json());
        }

        $this->components->info('Telegram webhook deleted.');

        return self::SUCCESS;
    }

    private function botToken(): ?string
    {
        $token = trim((string) config('services.telegram.bot_token'));

        if ($token === '') {
            $this->components->error('TELEGRAM_BOT_TOKEN is required.');

            return null;
        }

        return $token;
    }

    private function webhookSecret(): ?string
    {
        $secret = trim((string) ($this->option('secret') ?: config('services.telegram.webhook_secret')));

        if ($secret === '') {
            $this->components->error('TELEGRAM_WEBHOOK_SECRET is required for set.');

            return null;
        }

        if (Str::length($secret) > 256 || ! preg_match('/^[A-Za-z0-9_-]+$/', $secret)) {
            $this->components->error('Telegram webhook secret must be 1-256 characters using only A-Z, a-z, 0-9, underscore, and hyphen.');

            return null;
        }

        return $secret;
    }

    private function webhookUrl(): ?string
    {
        $url = trim((string) ($this->option('url') ?: config('services.telegram.webhook_url')));

        if ($url === '') {
            $url = rtrim((string) config('app.url'), '/').'/api/v1/webhooks/telegram/updates';
        }

        if (! filter_var($url, FILTER_VALIDATE_URL) || ! str_starts_with($url, 'https://')) {
            $this->components->error('Telegram webhook URL must be an absolute HTTPS URL.');

            return null;
        }

        return $url;
    }

    private function telegramApiUrl(string $token, string $method): string
    {
        return sprintf('https://api.telegram.org/bot%s/%s', $token, $method);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function failWithTelegramResponse(string $message, ?array $payload): int
    {
        $description = is_array($payload) ? (string) ($payload['description'] ?? '') : '';
        $this->components->error($description === '' ? $message : $message.' '.$description);

        return self::FAILURE;
    }

    private function failWith(string $message): int
    {
        $this->components->error($message);

        return self::FAILURE;
    }
}
