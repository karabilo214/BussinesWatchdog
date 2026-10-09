<?php

namespace Tests\Feature\Integrations;

use App\Models\AuditLog;
use App\Models\Integration;
use App\Models\IntegrationCredential;
use App\Models\Membership;
use App\Models\PairingCode;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Security\Keyring;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CredentialRotationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_pairing_encrypts_the_secret_with_the_current_keyring_version(): void
    {
        $this->useKeyringV2();
        [, , $store] = $this->owner();

        $paired = $this->pair($store);

        $credential = IntegrationCredential::query()->where('key_id', $paired['key_id'])->firstOrFail();
        $this->assertSame(2, $credential->key_version);
        $this->assertSame($paired['secret'], app(Keyring::class)->decrypt($credential->ciphertext, 2));
        $this->signed('/api/v1/ingest/heartbeat', $paired['key_id'], $paired['secret'])->assertOk();
    }

    public function test_failed_exchange_for_a_known_code_is_audited_without_the_code(): void
    {
        [, , $store] = $this->owner();
        $code = 'bwpc_'.Str::random(32);
        $pairingCode = $this->pairingCode($store, $code);

        $this->postJson('/api/v1/pairing/exchange', $this->exchangePayload($code, 'https://attacker.example.test'))
            ->assertStatus(422);

        $audit = AuditLog::query()->where('action', AuditLog::ACTION_INTEGRATION_PAIRING_FAILED)->firstOrFail();
        $this->assertSame($pairingCode->id, $audit->entity_id);
        $this->assertSame('pairing_base_url_mismatch', $audit->changes['reason_code']);
        $this->assertStringNotContainsString($code, json_encode($audit->changes));
    }

    public function test_pairing_exchange_is_rate_limited_per_ip(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/api/v1/pairing/exchange', $this->exchangePayload('bwpc_unknown_'.Str::random(24)))
                ->assertNotFound();
        }

        $this->postJson('/api/v1/pairing/exchange', $this->exchangePayload('bwpc_unknown_'.Str::random(24)))
            ->assertStatus(429);
    }

    public function test_admin_requests_rotation_and_heartbeat_signals_it(): void
    {
        [$user, $tenant, $store] = $this->owner('admin');
        $paired = $this->pair($store);

        $this->actingAs($user)
            ->withSession(['active_tenant_id' => $tenant->id])
            ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/v1/integrations/{$paired['integration_id']}/rotate", ['kind' => 'plugin_hmac'])
            ->assertStatus(202)
            ->assertJsonPath('rotation.status', 'requested')
            ->assertJsonMissingPath('credentials.0.secret');

        $this->signed('/api/v1/ingest/heartbeat', $paired['key_id'], $paired['secret'])
            ->assertOk()
            ->assertJsonPath('credential_rotation_requested', true);

        $this->assertDatabaseHas('audit_log', [
            'entity_id' => $paired['integration_id'],
            'action' => AuditLog::ACTION_INTEGRATION_CREDENTIAL_ROTATION_REQUESTED,
        ]);
    }

    public function test_rotation_request_rules(): void
    {
        [$user, $tenant, $store] = $this->owner('admin');
        $paired = $this->pair($store);
        $viewer = $this->member($tenant, 'viewer');

        $this->actingAs($user)
            ->withSession(['active_tenant_id' => $tenant->id])
            ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/v1/integrations/{$paired['integration_id']}/rotate", ['kind' => 'stripe_api'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'credential_kind_not_supported_yet');

        $this->actingAs($viewer)
            ->withSession(['active_tenant_id' => $tenant->id])
            ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/v1/integrations/{$paired['integration_id']}/rotate", ['kind' => 'plugin_hmac'])
            ->assertForbidden();
    }

    public function test_plugin_rotation_drains_the_old_key_until_the_new_key_is_used(): void
    {
        [, , $store] = $this->owner();
        $old = $this->pair($store);
        Integration::query()->whereKey($old['integration_id'])->update(['health' => json_encode(['credential_rotation_requested_at' => now()->toJSON()])]);

        $rotated = $this->signed('/api/v1/ingest/credentials/rotate', $old['key_id'], $old['secret'])
            ->assertCreated()
            ->assertJsonPath('secret_encoding', 'base64')
            ->json();

        $this->assertNotSame($old['key_id'], $rotated['key_id']);
        $oldCredential = IntegrationCredential::query()->where('key_id', $old['key_id'])->firstOrFail();
        $this->assertSame(IntegrationCredential::STATUS_DRAINING, $oldCredential->status);
        $this->assertEqualsWithDelta(24 * 3600, now()->diffInSeconds($oldCredential->expires_at), 5);
        $this->assertArrayNotHasKey('credential_rotation_requested_at', Integration::query()->findOrFail($old['integration_id'])->health);

        $this->signed('/api/v1/ingest/heartbeat', $old['key_id'], $old['secret'])->assertOk();
        $this->signed('/api/v1/ingest/heartbeat', $rotated['key_id'], $rotated['secret'])->assertOk();

        $this->assertSame(IntegrationCredential::STATUS_REVOKED, $oldCredential->fresh()->status);
        $this->signed('/api/v1/ingest/heartbeat', $old['key_id'], $old['secret'])
            ->assertUnauthorized()
            ->assertJsonPath('code', 'credential_revoked');
        $this->assertDatabaseHas('audit_log', ['action' => AuditLog::ACTION_INTEGRATION_CREDENTIAL_ROTATED]);
        $this->assertDatabaseHas('audit_log', ['action' => AuditLog::ACTION_INTEGRATION_CREDENTIAL_DRAINED]);
    }

    public function test_a_draining_key_expires_after_the_overlap(): void
    {
        [, , $store] = $this->owner();
        $old = $this->pair($store);
        $this->signed('/api/v1/ingest/credentials/rotate', $old['key_id'], $old['secret'])->assertCreated();

        Carbon::setTestNow(now()->addHours(24)->addMinute());

        $this->signed('/api/v1/ingest/heartbeat', $old['key_id'], $old['secret'])
            ->assertUnauthorized()
            ->assertJsonPath('code', 'credential_revoked');
    }

    public function test_repeated_rotation_after_a_lost_response_revokes_the_undelivered_key(): void
    {
        [, , $store] = $this->owner();
        $old = $this->pair($store);

        $lost = $this->signed('/api/v1/ingest/credentials/rotate', $old['key_id'], $old['secret'])->json();
        $drainingUntil = IntegrationCredential::query()->where('key_id', $old['key_id'])->value('expires_at');
        Carbon::setTestNow(now()->addMinutes(5));
        $delivered = $this->signed('/api/v1/ingest/credentials/rotate', $old['key_id'], $old['secret'])->assertCreated()->json();

        $this->assertSame(IntegrationCredential::STATUS_REVOKED, IntegrationCredential::query()->where('key_id', $lost['key_id'])->value('status'));
        $this->assertSame(IntegrationCredential::STATUS_ACTIVE, IntegrationCredential::query()->where('key_id', $delivered['key_id'])->value('status'));
        $this->assertEquals($drainingUntil, IntegrationCredential::query()->where('key_id', $old['key_id'])->value('expires_at'));
    }

    public function test_reencrypt_command_moves_secrets_to_the_current_keyring_version(): void
    {
        [, $tenant, $store] = $this->owner();
        $paired = $this->pair($store);
        $this->useKeyringV2();

        $this->artisan('security:reencrypt-secrets')->assertSuccessful();

        $this->assertSame(2, IntegrationCredential::query()->where('key_id', $paired['key_id'])->value('key_version'));
        $this->signed('/api/v1/ingest/heartbeat', $paired['key_id'], $paired['secret'])->assertOk();
    }

    public function test_keyring_is_not_ready_when_the_current_version_has_no_key(): void
    {
        $this->assertTrue(app(Keyring::class)->isReady());

        config(['watchdog.keyring.current' => 3]);
        $this->app->forgetInstance(Keyring::class);

        $this->assertFalse(app(Keyring::class)->isReady());
    }

    private function useKeyringV2(): void
    {
        config([
            'watchdog.keyring.current' => 2,
            'watchdog.keyring.additional_keys' => '2:base64:'.base64_encode(random_bytes(32)),
        ]);
        $this->app->forgetInstance(Keyring::class);
    }

    /**
     * @return array{integration_id: string, key_id: string, secret: string}
     */
    private function pair(Store $store): array
    {
        $code = 'bwpc_'.Str::random(32);
        $this->pairingCode($store, $code);

        $response = $this->postJson('/api/v1/pairing/exchange', $this->exchangePayload($code))->assertCreated();

        return [
            'integration_id' => $response->json('integration_id'),
            'key_id' => $response->json('key_id'),
            'secret' => $response->json('secret'),
        ];
    }

    private function signed(string $path, string $keyId, string $secretBase64, string $body = '{}'): TestResponse
    {
        $timestamp = (string) Carbon::now()->getTimestamp();
        $nonce = (string) Str::uuid();
        $signature = hash_hmac('sha256', implode("\n", [
            'v1', $timestamp, $nonce, 'POST', $path, hash('sha256', $body),
        ]), base64_decode($secretBase64, true));

        return $this->call('POST', $path, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_BW_KEY_ID' => $keyId,
            'HTTP_X_BW_TIMESTAMP' => $timestamp,
            'HTTP_X_BW_NONCE' => $nonce,
            'HTTP_X_BW_SIGNATURE' => $signature,
            'HTTP_X_BW_SIGNATURE_VERSION' => '1',
        ], $body);
    }

    /**
     * @return array<string, string>
     */
    private function exchangePayload(string $code, string $baseUrl = 'https://shop.example.test'): array
    {
        return [
            'pairing_code' => $code,
            'install_id' => (string) Str::uuid(),
            'connector_code' => 'custom_crm',
            'plugin_version' => '1.2.3',
            'base_url' => $baseUrl,
        ];
    }

    /**
     * @return array{User, Tenant, Store}
     */
    private function owner(string $role = 'owner'): array
    {
        $tenant = Tenant::query()->create(['name' => 'Demo', 'timezone' => 'Europe/Kyiv']);
        $user = $this->member($tenant, $role);
        $store = Store::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Main Shop',
            'base_url' => 'https://shop.example.test',
            'timezone' => 'Europe/Kyiv',
            'default_currency' => 'EUR',
        ]);

        return [$user, $tenant, $store];
    }

    private function member(Tenant $tenant, string $role): User
    {
        $user = User::query()->create([
            'name' => 'Member',
            'email' => fake()->unique()->safeEmail(),
            'password_hash' => Hash::make('very-secure-password'),
            'locale' => 'ru',
        ]);
        Membership::query()->create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => $role]);

        return $user;
    }

    private function pairingCode(Store $store, string $code): PairingCode
    {
        return PairingCode::query()->create([
            'tenant_id' => $store->tenant_id,
            'store_id' => $store->id,
            'code_hash' => hash('sha256', $code),
            'created_by' => User::query()->firstOrFail()->id,
            'expires_at' => now()->addMinutes(15),
            'created_at' => now(),
        ]);
    }
}
