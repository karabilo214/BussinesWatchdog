<?php

namespace Tests\Feature\Notifications;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramWebhookCommandTest extends TestCase
{
    public function test_it_registers_the_native_telegram_update_webhook(): void
    {
        config()->set('services.telegram.bot_token', 'bot-token');
        config()->set('services.telegram.webhook_secret', 'telegram-secret');
        config()->set('services.telegram.webhook_url', 'https://watchdog.example.test/api/v1/webhooks/telegram/updates');
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => true], 200),
        ]);

        $this->artisan('telegram:webhook set --drop-pending-updates')
            ->expectsOutputToContain('Telegram webhook registered.')
            ->assertSuccessful();

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/botbot-token/setWebhook'
            && $request['url'] === 'https://watchdog.example.test/api/v1/webhooks/telegram/updates'
            && $request['secret_token'] === 'telegram-secret'
            && $request['allowed_updates'] === ['message', 'channel_post']
            && $request['drop_pending_updates'] === true);
    }

    public function test_it_refuses_to_register_without_secret_or_https_url(): void
    {
        config()->set('services.telegram.bot_token', 'bot-token');
        config()->set('services.telegram.webhook_secret', '');
        config()->set('services.telegram.webhook_url', 'https://watchdog.example.test/api/v1/webhooks/telegram/updates');
        Http::fake();

        $this->artisan('telegram:webhook set')->assertFailed();

        config()->set('services.telegram.webhook_secret', 'telegram-secret');
        config()->set('services.telegram.webhook_url', 'http://watchdog.example.test/api/v1/webhooks/telegram/updates');

        $this->artisan('telegram:webhook set')->assertFailed();
        Http::assertNothingSent();
    }

    public function test_it_reports_telegram_api_rejection(): void
    {
        config()->set('services.telegram.bot_token', 'bot-token');
        config()->set('services.telegram.webhook_secret', 'telegram-secret');
        config()->set('services.telegram.webhook_url', 'https://watchdog.example.test/api/v1/webhooks/telegram/updates');
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'bad webhook'], 400),
        ]);

        $this->artisan('telegram:webhook set')
            ->expectsOutputToContain('Telegram webhook registration failed. bad webhook')
            ->assertFailed();
    }

    public function test_it_reads_and_deletes_the_registered_webhook(): void
    {
        config()->set('services.telegram.bot_token', 'bot-token');
        Http::fake([
            '*/getWebhookInfo' => Http::response([
                'ok' => true,
                'result' => [
                    'url' => 'https://watchdog.example.test/api/v1/webhooks/telegram/updates',
                    'pending_update_count' => 2,
                ],
            ], 200),
            '*/deleteWebhook' => Http::response(['ok' => true, 'result' => true], 200),
        ]);

        $this->artisan('telegram:webhook info')
            ->expectsOutputToContain('Telegram webhook info.')
            ->expectsOutputToContain('pending_update_count=2')
            ->assertSuccessful();

        $this->artisan('telegram:webhook delete')
            ->expectsOutputToContain('Telegram webhook deleted.')
            ->assertSuccessful();

        Http::assertSentCount(2);
    }
}
