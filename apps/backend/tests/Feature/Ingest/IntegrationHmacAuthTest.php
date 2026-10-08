<?php

namespace Tests\Feature\Ingest;

use App\Models\Integration;
use App\Models\IntegrationCredential;
use App\Models\Store;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class IntegrationHmacAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_signed_heartbeat_is_accepted_and_updates_integration(): void
    {
        [$integration, $credential, $secret] = $this->integrationCredential();
        $body = '{"status":"ok"}';

        $this->callSignedHeartbeat($credential->key_id, $secret, $body)
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('integration_id', $integration->id);

        $this->assertNotNull($integration->refresh()->last_heartbeat_at);
    }

    public function test_missing_signature_headers_are_rejected(): void
    {
        $this->postJson('/api/v1/ingest/heartbeat', ['status' => 'ok'])
            ->assertUnauthorized()
            ->assertJsonPath('code', 'signature_invalid');
    }

    public function test_altered_body_signature_is_rejected(): void
    {
        [, $credential, $secret] = $this->integrationCredential();
        $timestamp = (string) time();
        $nonce = '550e8400-e29b-41d4-a716-446655440000';
        $signature = $this->signature($secret, $timestamp, $nonce, '{"status":"ok"}');

        $this->call('POST', '/api/v1/ingest/heartbeat', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_BW_KEY_ID' => $credential->key_id,
            'HTTP_X_BW_TIMESTAMP' => $timestamp,
            'HTTP_X_BW_NONCE' => $nonce,
            'HTTP_X_BW_SIGNATURE' => $signature,
            'HTTP_X_BW_SIGNATURE_VERSION' => '1',
        ], '{"status":"changed"}')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'signature_invalid');
    }

    public function test_nonce_replay_is_rejected_after_valid_signature(): void
    {
        [, $credential, $secret] = $this->integrationCredential();
        $body = '{"status":"ok"}';
        $timestamp = (string) time();
        $nonce = '550e8400-e29b-41d4-a716-446655440000';

        $this->callSignedHeartbeat($credential->key_id, $secret, $body, $timestamp, $nonce)
            ->assertOk();

        $this->callSignedHeartbeat($credential->key_id, $secret, $body, $timestamp, $nonce)
            ->assertUnauthorized()
            ->assertJsonPath('code', 'nonce_replayed');
    }

    public function test_stale_timestamp_is_rejected(): void
    {
        [, $credential, $secret] = $this->integrationCredential();

        $this->callSignedHeartbeat($credential->key_id, $secret, '{"status":"ok"}', (string) (time() - 301))
            ->assertUnauthorized()
            ->assertJsonPath('code', 'timestamp_out_of_range');
    }

    public function test_revoked_credential_is_rejected(): void
    {
        [, $credential, $secret] = $this->integrationCredential(status: 'revoked');

        $this->callSignedHeartbeat($credential->key_id, $secret, '{"status":"ok"}')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'credential_revoked');
    }

    public function test_query_string_is_rejected_for_signed_ingest(): void
    {
        [, $credential, $secret] = $this->integrationCredential();
        $body = '{"status":"ok"}';
        $timestamp = (string) time();
        $nonce = '550e8400-e29b-41d4-a716-446655440000';
        $signature = $this->signature($secret, $timestamp, $nonce, $body);

        $this->call('POST', '/api/v1/ingest/heartbeat?debug=1', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_BW_KEY_ID' => $credential->key_id,
            'HTTP_X_BW_TIMESTAMP' => $timestamp,
            'HTTP_X_BW_NONCE' => $nonce,
            'HTTP_X_BW_SIGNATURE' => $signature,
            'HTTP_X_BW_SIGNATURE_VERSION' => '1',
        ], $body)
            ->assertUnauthorized()
            ->assertJsonPath('code', 'signature_invalid');
    }

    /**
     * @return array{0: Integration, 1: IntegrationCredential, 2: string}
     */
    private function integrationCredential(string $status = 'active'): array
    {
        $tenant = Tenant::query()->create([
            'name' => 'Demo Store',
            'timezone' => 'Europe/Kyiv',
        ]);
        $store = Store::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Main Shop',
            'base_url' => 'https://shop.example.test',
            'timezone' => 'Europe/Kyiv',
            'default_currency' => 'EUR',
        ]);
        $integration = Integration::query()->create([
            'tenant_id' => $tenant->id,
            'store_id' => $store->id,
            'provider' => 'custom_crm',
            'install_id' => '550e8400-e29b-41d4-a716-446655440000',
            'mode' => 'live',
            'source_authority' => Integration::SOURCE_STORE_REPORTED,
            'status' => Integration::STATUS_ACTIVE,
            'capabilities' => [],
            'connector_version' => '1.0.0',
            'health' => [],
        ]);
        $secret = random_bytes(32);
        $credential = IntegrationCredential::query()->create([
            'tenant_id' => $tenant->id,
            'store_id' => $store->id,
            'integration_id' => $integration->id,
            'kind' => IntegrationCredential::KIND_PLUGIN_HMAC,
            'key_id' => 'bwk_test_key',
            'ciphertext' => Crypt::encryptString(base64_encode($secret)),
            'key_version' => 1,
            'fingerprint' => hash('sha256', $secret),
            'status' => $status,
            'created_at' => now(),
        ]);

        return [$integration, $credential, $secret];
    }

    private function callSignedHeartbeat(
        string $keyId,
        string $secret,
        string $body,
        ?string $timestamp = null,
        string $nonce = '550e8400-e29b-41d4-a716-446655440000',
    ): \Illuminate\Testing\TestResponse {
        $timestamp ??= (string) time();

        return $this->call('POST', '/api/v1/ingest/heartbeat', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_BW_KEY_ID' => $keyId,
            'HTTP_X_BW_TIMESTAMP' => $timestamp,
            'HTTP_X_BW_NONCE' => $nonce,
            'HTTP_X_BW_SIGNATURE' => $this->signature($secret, $timestamp, $nonce, $body),
            'HTTP_X_BW_SIGNATURE_VERSION' => '1',
        ], $body);
    }

    private function signature(string $secret, string $timestamp, string $nonce, string $body): string
    {
        return hash_hmac('sha256', implode("\n", [
            'v1',
            $timestamp,
            strtolower($nonce),
            'POST',
            '/api/v1/ingest/heartbeat',
            hash('sha256', $body),
        ]), $secret);
    }
}
