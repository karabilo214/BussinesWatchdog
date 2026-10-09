<?php

namespace Tests\Feature\Notifications;

use App\Mail\NotificationMessageMail;
use App\Models\AuditLog;
use App\Models\NotificationChannel;
use App\Models\NotificationDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_telegram_channels_are_rejected_until_binding_is_implemented(): void
    {
        $context = $this->context('owner');

        $this->createChannel($context, ['kind' => 'telegram', 'destination_email' => null])
            ->assertStatus(422)
            ->assertJsonPath('code', 'channel_kind_not_supported_yet');
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
            'preferences' => ['quiet_hours' => ['start' => '22:00', 'end' => '08:00'], 'min_severity' => 'critical'],
        ])
            ->assertOk()
            ->assertJsonPath('effective_preferences.locale', 'de')
            ->assertJsonPath('effective_preferences.min_severity', 'critical')
            ->assertJsonPath('effective_preferences.quiet_hours.start', '22:00');
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
