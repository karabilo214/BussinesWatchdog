<?php

namespace Tests\Feature\Notifications;

use App\Mail\NotificationMessageMail;
use App\Models\AuditLog;
use App\Models\NotificationChannel;
use App\Models\NotificationChannelVerification;
use App\Models\NotificationDelivery;
use App\Support\Notifications\NotificationDeliveryWorker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class NotificationChannelApiTest extends TestCase
{
    use NotificationTestContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_creating_an_email_channel_encrypts_the_destination_and_mails_a_verification_code(): void
    {
        $context = $this->context('admin');

        $response = $this->createChannel($context, ['destination_email' => 'alerts@example.test']);

        $response
            ->assertCreated()
            ->assertJsonPath('kind', 'email')
            ->assertJsonPath('destination_masked', 'a***@example.test')
            ->assertJsonPath('enabled', false)
            ->assertJsonPath('verified_at', null)
            ->assertJsonPath('effective_preferences.min_severity', 'warning')
            ->assertJsonPath('effective_preferences.locale', 'ru')
            ->assertJsonPath('verification.method', 'email_code');

        $channel = NotificationChannel::query()->firstOrFail();
        $this->assertStringNotContainsString('alerts@example.test', $channel->getRawOriginal('destination_ciphertext'));
        $this->assertStringNotContainsString('alerts@example.test', (string) $response->getContent());
        $this->assertNotNull($this->sentCode('alerts@example.test'));
        $this->assertDatabaseHas('audit_log', [
            'entity_id' => $channel->id,
            'action' => AuditLog::ACTION_NOTIFICATION_CHANNEL_CREATED,
        ]);
        $this->assertSame(0, NotificationDelivery::query()->count());
    }

    public function test_telegram_channel_is_created_with_a_bot_binding_code_and_bound_by_the_bot(): void
    {
        $context = $this->context('owner');
        config()->set('services.telegram.bot_username', 'BusinessWatchdogBot');
        config()->set('services.telegram.webhook_secret', 'telegram-secret');

        $response = $this->createChannel($context, ['kind' => 'telegram', 'destination_email' => null]);

        $response
            ->assertCreated()
            ->assertJsonPath('kind', 'telegram')
            ->assertJsonPath('destination_masked', 'Telegram: awaiting binding')
            ->assertJsonPath('enabled', false)
            ->assertJsonPath('verified_at', null)
            ->assertJsonPath('binding.method', 'telegram_code')
            ->assertJsonPath('binding.bot_username', 'BusinessWatchdogBot');

        $code = $response->json('binding.binding_code');
        $channel = NotificationChannel::query()->firstOrFail();

        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->assertStringNotContainsString($code, $channel->getRawOriginal('destination_ciphertext'));
        Mail::assertNothingSent();

        $this->postJson('/api/v1/webhooks/telegram/bind', [
            'binding_code' => $code,
            'chat_id' => '-1001234567890',
            'chat_title' => 'Ops Room',
        ])->assertUnauthorized();

        $this->withHeaders(['X-Business-Watchdog-Telegram-Secret' => 'telegram-secret'])
            ->postJson('/api/v1/webhooks/telegram/bind', [
                'binding_code' => $code,
                'chat_id' => '-1001234567890',
                'chat_title' => 'Ops Room',
            ])
            ->assertOk()
            ->assertJsonPath('channel_id', $channel->id)
            ->assertJsonPath('status', 'bound');

        $this->asMember($context)->getJson('/api/v1/notification-channels')
            ->assertOk()
            ->assertJsonPath('data.0.destination_masked', 'Telegram chat ***7890')
            ->assertJsonPath('data.0.enabled', true);
    }

    public function test_bound_telegram_channel_can_send_a_test_message(): void
    {
        $context = $this->context('owner');
        config()->set('services.telegram.bot_token', 'bot-token');
        config()->set('services.telegram.webhook_secret', 'telegram-secret');
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 321]], 200),
        ]);

        $code = $this->createChannel($context, ['kind' => 'telegram', 'destination_email' => null])->json('binding.binding_code');
        $channel = NotificationChannel::query()->firstOrFail();

        $this->withHeaders(['X-Business-Watchdog-Telegram-Secret' => 'telegram-secret'])
            ->postJson('/api/v1/webhooks/telegram/bind', [
                'binding_code' => $code,
                'chat_id' => '1234567890',
            ])->assertOk();

        $this->asMember($context)->postJson("/api/v1/notification-channels/{$channel->id}/test")
            ->assertStatus(202)
            ->assertJsonPath('status', 'queued');

        $totals = app(NotificationDeliveryWorker::class)->deliverDue();
        $this->assertSame(1, $totals['sent']);
        $this->assertDatabaseHas('notification_deliveries', [
            'channel_id' => $channel->id,
            'status' => NotificationDelivery::STATUS_SENT,
            'provider_message_id' => '321',
        ]);
    }

    public function test_telegram_update_webhook_binds_a_chat_from_a_native_message(): void
    {
        $context = $this->context('owner');
        config()->set('services.telegram.webhook_secret', 'telegram-secret');

        $code = $this->createChannel($context, ['kind' => 'telegram', 'destination_email' => null])->json('binding.binding_code');
        $channel = NotificationChannel::query()->firstOrFail();

        $this->postJson('/api/v1/webhooks/telegram/updates', [
            'update_id' => 1,
            'message' => [
                'message_id' => 10,
                'text' => "Business Watchdog {$code}",
                'chat' => ['id' => -1009876543210, 'title' => 'Ops Room'],
            ],
        ])->assertUnauthorized();

        $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret'])
            ->postJson('/api/v1/webhooks/telegram/updates', [
                'update_id' => 2,
                'message' => [
                    'message_id' => 11,
                    'text' => "Business Watchdog {$code}",
                    'chat' => ['id' => -1009876543210, 'title' => 'Ops Room'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('channel_id', $channel->id)
            ->assertJsonPath('status', 'bound');

        $this->asMember($context)->getJson('/api/v1/notification-channels')
            ->assertOk()
            ->assertJsonPath('data.0.destination_masked', 'Telegram chat ***3210')
            ->assertJsonPath('data.0.enabled', true);
    }

    public function test_telegram_update_without_a_binding_code_is_ignored(): void
    {
        config()->set('services.telegram.webhook_secret', 'telegram-secret');

        $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret'])
            ->postJson('/api/v1/webhooks/telegram/updates', [
                'update_id' => 3,
                'message' => [
                    'message_id' => 12,
                    'text' => '/start',
                    'chat' => ['id' => 1234567890],
                ],
            ])
            ->assertAccepted()
            ->assertJsonPath('status', 'ignored');
    }

    public function test_telegram_binding_rejects_ambiguous_active_codes(): void
    {
        $context = $this->context('owner');
        config()->set('services.telegram.webhook_secret', 'telegram-secret');

        $firstCode = $this->createChannel($context, ['kind' => 'telegram', 'destination_email' => null])->json('binding.binding_code');
        $secondContext = $this->context('owner');
        $this->createChannel($secondContext, ['kind' => 'telegram', 'destination_email' => null]);

        $secondChannel = NotificationChannel::query()->where('tenant_id', $secondContext['tenant']->id)->firstOrFail();
        NotificationChannelVerification::query()
            ->where('channel_id', $secondChannel->id)
            ->update(['code_hash' => hash_hmac('sha256', $firstCode, (string) config('app.key'))]);

        $this->withHeaders(['X-Business-Watchdog-Telegram-Secret' => 'telegram-secret'])
            ->postJson('/api/v1/webhooks/telegram/bind', [
                'binding_code' => $firstCode,
                'chat_id' => '1234567890',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'telegram_binding_code_collision');

        $this->assertSame(0, NotificationChannel::query()->whereNotNull('verified_at')->count());
    }

    public function test_verification_with_the_mailed_code_enables_the_channel(): void
    {
        $context = $this->context('owner');
        $channelId = $this->createChannel($context)->json('id');
        $code = $this->sentCode('alerts@example.test');
        $wrong = $code === '000000' ? '111111' : '000000';

        $this->asMember($context)->postJson("/api/v1/notification-channels/{$channelId}/verify", ['code' => $wrong])
            ->assertStatus(422)
            ->assertJsonPath('code', 'verification_code_invalid');

        $this->asMember($context)->postJson("/api/v1/notification-channels/{$channelId}/verify", ['code' => $code])
            ->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('verified_at', fn ($value) => $value !== null);

        $this->asMember($context)->postJson("/api/v1/notification-channels/{$channelId}/verify", ['code' => $code])
            ->assertStatus(409)
            ->assertJsonPath('code', 'channel_already_verified');
    }

    public function test_verification_is_locked_after_too_many_wrong_codes_and_expires(): void
    {
        $context = $this->context('owner');
        $channelId = $this->createChannel($context)->json('id');
        $code = $this->sentCode('alerts@example.test');
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 5; $i++) {
            $this->asMember($context)->postJson("/api/v1/notification-channels/{$channelId}/verify", ['code' => $wrong])
                ->assertStatus(422);
        }

        $this->asMember($context)->postJson("/api/v1/notification-channels/{$channelId}/verify", ['code' => $code])
            ->assertStatus(429)
            ->assertJsonPath('code', 'verification_attempts_exceeded');

        $otherId = $this->createChannel($context, ['destination_email' => 'second@example.test'])->json('id');
        $this->travel(16)->minutes();

        $this->asMember($context)->postJson("/api/v1/notification-channels/{$otherId}/verify", [
            'code' => $this->sentCode('second@example.test'),
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'verification_code_expired');
    }

    public function test_a_new_code_can_be_requested_once_a_minute_to_the_saved_address(): void
    {
        $context = $this->context('owner');
        $channelId = $this->createChannel($context)->json('id');
        $first = $this->sentCode('alerts@example.test');

        $this->asMember($context)->postJson("/api/v1/notification-channels/{$channelId}/verification-code")
            ->assertStatus(429)
            ->assertJsonPath('code', 'verification_resend_rate_limited');

        $this->travel(61)->seconds();

        $this->asMember($context)->postJson("/api/v1/notification-channels/{$channelId}/verification-code")
            ->assertStatus(202)
            ->assertJsonPath('method', 'email_code')
            ->assertJsonPath('expires_in_seconds', 900);

        Mail::assertSent(NotificationMessageMail::class, 2);
        $second = $this->sentCode('alerts@example.test');

        if ($first !== $second) {
            $this->asMember($context)->postJson("/api/v1/notification-channels/{$channelId}/verify", ['code' => $first])
                ->assertStatus(422);
        }

        $this->asMember($context)->postJson("/api/v1/notification-channels/{$channelId}/verify", ['code' => $second])
            ->assertOk()
            ->assertJsonPath('enabled', true);

        $this->travel(61)->seconds();

        $this->asMember($context)->postJson("/api/v1/notification-channels/{$channelId}/verification-code")
            ->assertStatus(409)
            ->assertJsonPath('code', 'channel_already_verified');

        $operator = $this->context('operator', $context['tenant']);

        $this->asMember($operator)->postJson("/api/v1/notification-channels/{$channelId}/verification-code")
            ->assertForbidden();
    }

    public function test_an_unverified_channel_cannot_be_enabled(): void
    {
        $context = $this->context('owner');
        $channelId = $this->createChannel($context)->json('id');

        $this->asMember($context)->patchJson("/api/v1/notification-channels/{$channelId}", ['enabled' => true])
            ->assertStatus(422)
            ->assertJsonPath('code', 'channel_not_verified');
    }

    public function test_changing_the_destination_requires_reverification_and_is_audited(): void
    {
        $context = $this->context('owner');
        $channel = $this->verifiedEmailChannel($context['tenant']);

        $this->asMember($context)->patchJson("/api/v1/notification-channels/{$channel->id}", [
            'destination_email' => 'new-inbox@example.test',
        ])
            ->assertOk()
            ->assertJsonPath('destination_masked', 'n***@example.test')
            ->assertJsonPath('enabled', false)
            ->assertJsonPath('verified_at', null);

        $this->assertNotNull($this->sentCode('new-inbox@example.test'));
        $audit = AuditLog::query()
            ->where('entity_id', $channel->id)
            ->where('action', AuditLog::ACTION_NOTIFICATION_CHANNEL_UPDATED)
            ->firstOrFail();
        $this->assertSame('replaced_requires_verification', $audit->changes['destination']);
        $this->assertStringNotContainsString('new-inbox@example.test', json_encode($audit->changes));
    }

    public function test_preferences_are_validated_and_merged(): void
    {
        $context = $this->context('owner');
        $channel = $this->verifiedEmailChannel($context['tenant'], 'alerts@example.test', ['locale' => 'de']);

        $this->asMember($context)->patchJson("/api/v1/notification-channels/{$channel->id}", [
            'preferences' => ['quiet_hours' => ['start' => '25:00', 'end' => '08:00']],
        ])->assertStatus(422);

        $this->asMember($context)->patchJson("/api/v1/notification-channels/{$channel->id}", [
            'preferences' => ['reminder_hours' => 23],
        ])->assertStatus(422);

        $this->asMember($context)->patchJson("/api/v1/notification-channels/{$channel->id}", [
            'preferences' => ['daily_digest_at' => '25:00'],
        ])->assertStatus(422);

        $this->asMember($context)->patchJson("/api/v1/notification-channels/{$channel->id}", [
            'preferences' => ['quiet_hours' => ['start' => '22:00', 'end' => '08:00'], 'min_severity' => 'critical', 'reminder_hours' => 48],
        ])
            ->assertOk()
            ->assertJsonPath('effective_preferences.locale', 'de')
            ->assertJsonPath('effective_preferences.min_severity', 'critical')
            ->assertJsonPath('effective_preferences.quiet_hours.start', '22:00')
            ->assertJsonPath('effective_preferences.reminder_hours', 48)
            ->assertJsonPath('effective_preferences.daily_digest_at', null);

        $this->asMember($context)->patchJson("/api/v1/notification-channels/{$channel->id}", [
            'preferences' => ['daily_digest_at' => '07:30'],
        ])
            ->assertOk()
            ->assertJsonPath('preferences.daily_digest_at', '07:30')
            ->assertJsonPath('effective_preferences.daily_digest_at', '07:30')
            ->assertJsonPath('effective_preferences.reminder_hours', 48);
    }

    public function test_store_filter_must_reference_own_stores(): void
    {
        $context = $this->context('owner');
        $foreign = $this->context('owner');
        $channel = $this->verifiedEmailChannel($context['tenant']);

        $this->asMember($context)->patchJson("/api/v1/notification-channels/{$channel->id}", [
            'preferences' => ['store_ids' => [$foreign['store']->id]],
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'store_not_found');
    }

    public function test_only_the_owner_can_let_critical_incidents_bypass_quiet_hours(): void
    {
        $owner = $this->context('owner');
        $admin = $this->context('admin', $owner['tenant'], $owner['store'], $owner['integration']);
        $channel = $this->verifiedEmailChannel($owner['tenant']);
        $payload = ['preferences' => ['critical_bypasses_quiet_hours' => true]];

        $this->asMember($admin)->patchJson("/api/v1/notification-channels/{$channel->id}", $payload)
            ->assertForbidden()
            ->assertJsonPath('code', 'critical_bypass_requires_owner');

        $this->asMember($owner)->patchJson("/api/v1/notification-channels/{$channel->id}", $payload)
            ->assertOk()
            ->assertJsonPath('effective_preferences.critical_bypasses_quiet_hours', true);
    }

    public function test_test_notification_is_limited_to_one_per_minute_and_needs_a_verified_channel(): void
    {
        $context = $this->context('owner');
        $channel = $this->verifiedEmailChannel($context['tenant']);

        $this->asMember($context)->postJson("/api/v1/notification-channels/{$channel->id}/test")
            ->assertStatus(202)
            ->assertJsonPath('notification_kind', 'test')
            ->assertJsonPath('status', 'queued');

        $this->asMember($context)->postJson("/api/v1/notification-channels/{$channel->id}/test")
            ->assertStatus(429)
            ->assertJsonPath('code', 'test_rate_limited');

        $unverifiedId = $this->createChannel($context, ['destination_email' => 'pending@example.test'])->json('id');
        $this->asMember($context)->postJson("/api/v1/notification-channels/{$unverifiedId}/test")
            ->assertStatus(422)
            ->assertJsonPath('code', 'channel_not_verified');
    }

    public function test_operator_and_viewer_cannot_manage_channels_and_foreign_channels_are_hidden(): void
    {
        $context = $this->context('owner');
        $channel = $this->verifiedEmailChannel($context['tenant']);
        $operator = $this->context('operator', $context['tenant'], $context['store'], $context['integration']);
        $foreign = $this->context('owner');

        $this->createChannel($operator)->assertForbidden();
        $this->asMember($operator)->getJson('/api/v1/notification-channels')->assertForbidden();

        $this->asMember($foreign)->patchJson("/api/v1/notification-channels/{$channel->id}", ['label' => 'x'])
            ->assertNotFound();
        $this->asMember($foreign)->postJson("/api/v1/notification-channels/{$channel->id}/test")
            ->assertNotFound();
        $this->asMember($foreign)->getJson('/api/v1/notification-channels')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_deliveries_list_exposes_uncertain_state_and_is_tenant_scoped(): void
    {
        $context = $this->context('viewer');
        $channel = $this->verifiedEmailChannel($context['tenant']);
        NotificationDelivery::query()->create([
            'tenant_id' => $context['tenant']->id,
            'channel_id' => $channel->id,
            'notification_kind' => NotificationDelivery::KIND_TEST,
            'dedupe_key' => 'test:202610091200',
            'template_version' => 'test.v1',
            'sanitized_content' => ['kind' => 'test', 'locale' => 'ru', 'timezone' => 'UTC', 'channel_label' => 'Ops'],
            'status' => NotificationDelivery::STATUS_UNCERTAIN,
            'attempts' => 1,
            'next_attempt_at' => now(),
            'error_code' => 'email_transport_timeout',
            'created_at' => now(),
        ]);
        $foreign = $this->context('owner');

        $this->asMember($context)->getJson('/api/v1/notification-deliveries?status=uncertain')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.delivery_uncertain', true)
            ->assertJsonPath('data.0.error_code', 'email_transport_timeout');

        $this->asMember($foreign)->getJson('/api/v1/notification-deliveries')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function asMember(array $context): static
    {
        return $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id]);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $overrides
     */
    private function createChannel(array $context, array $overrides = []): TestResponse
    {
        return $this->asMember($context)
            ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/notification-channels', array_merge([
                'kind' => 'email',
                'label' => 'Ops inbox',
                'destination_email' => 'alerts@example.test',
            ], $overrides));
    }

    private function sentCode(string $email): ?string
    {
        $code = null;

        Mail::assertSent(NotificationMessageMail::class, function (NotificationMessageMail $mail) use ($email, &$code): bool {
            if (! $mail->hasTo($email)) {
                return false;
            }

            preg_match('/\b(\d{6})\b/', $mail->bodyText, $matches);
            $code = $matches[1] ?? null;

            return $code !== null;
        });

        return $code;
    }
}
