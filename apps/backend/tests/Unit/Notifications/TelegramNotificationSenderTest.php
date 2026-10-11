<?php

namespace Tests\Unit\Notifications;

use App\Support\Notifications\Channels\SendResult;
use App\Support\Notifications\Channels\TelegramNotificationSender;
use App\Support\Notifications\RenderedNotification;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramNotificationSenderTest extends TestCase
{
    public function test_success_posts_to_the_telegram_bot_api_and_returns_message_id(): void
    {
        config()->set('services.telegram.bot_token', 'bot-token');
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 321]], 200),
        ]);

        $result = $this->sender()->send($this->destination(), $this->message());

        $this->assertSame(SendResult::OUTCOME_SENT, $result->outcome);
        $this->assertSame('321', $result->providerMessageId);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/botbot-token/sendMessage'
            && $request['chat_id'] === '1234567890'
            && $request['text'] === "Subject\n\nBody"
            && $request['disable_web_page_preview'] === true);
    }

    public function test_rate_limit_returns_transient_failure_with_retry_after(): void
    {
        config()->set('services.telegram.bot_token', 'bot-token');
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => false, 'parameters' => ['retry_after' => 37]], 429),
        ]);

        $result = $this->sender()->send($this->destination(), $this->message());

        $this->assertSame(SendResult::OUTCOME_TRANSIENT_FAILURE, $result->outcome);
        $this->assertSame('telegram_rate_limited', $result->errorCode);
        $this->assertSame(37, $result->retryAfterSeconds);
    }

    public function test_server_error_is_retryable_and_client_reject_is_permanent(): void
    {
        config()->set('services.telegram.bot_token', 'bot-token');
        Http::fake([
            'api.telegram.org/*' => Http::sequence()
                ->push(['ok' => false], 502)
                ->push(['ok' => false, 'description' => 'chat not found'], 400),
        ]);

        $retry = $this->sender()->send($this->destination(), $this->message());
        $dead = $this->sender()->send($this->destination(), $this->message());

        $this->assertSame(SendResult::OUTCOME_TRANSIENT_FAILURE, $retry->outcome);
        $this->assertSame('telegram_transport_error', $retry->errorCode);
        $this->assertSame(SendResult::OUTCOME_PERMANENT_FAILURE, $dead->outcome);
        $this->assertSame('telegram_rejected', $dead->errorCode);
    }

    public function test_invalid_configuration_and_destination_are_permanent_failures(): void
    {
        config()->set('services.telegram.bot_token', '');
        $missingToken = $this->sender()->send($this->destination(), $this->message());

        config()->set('services.telegram.bot_token', 'bot-token');
        $invalidJson = $this->sender()->send('not-json', $this->message());
        $missingChat = $this->sender()->send(json_encode(['chat_title' => 'Ops'], JSON_THROW_ON_ERROR), $this->message());

        $this->assertSame(SendResult::OUTCOME_PERMANENT_FAILURE, $missingToken->outcome);
        $this->assertSame('telegram_bot_not_configured', $missingToken->errorCode);
        $this->assertSame(SendResult::OUTCOME_PERMANENT_FAILURE, $invalidJson->outcome);
        $this->assertSame('telegram_destination_invalid', $invalidJson->errorCode);
        $this->assertSame(SendResult::OUTCOME_PERMANENT_FAILURE, $missingChat->outcome);
        $this->assertSame('telegram_destination_invalid', $missingChat->errorCode);
    }

    public function test_transport_exception_is_uncertain(): void
    {
        config()->set('services.telegram.bot_token', 'bot-token');
        Http::fake(fn (): never => throw new \RuntimeException('timeout'));

        $result = $this->sender()->send($this->destination(), $this->message());

        $this->assertSame(SendResult::OUTCOME_UNCERTAIN, $result->outcome);
        $this->assertSame('telegram_transport_timeout', $result->errorCode);
    }

    private function sender(): TelegramNotificationSender
    {
        return new TelegramNotificationSender();
    }

    private function message(): RenderedNotification
    {
        return new RenderedNotification('Subject', 'Body');
    }

    private function destination(): string
    {
        return json_encode(['chat_id' => '1234567890', 'chat_title' => 'Ops'], JSON_THROW_ON_ERROR);
    }
}
