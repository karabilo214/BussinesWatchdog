<?php

namespace Tests\Feature\Ingest;

use App\Models\Integration;
use App\Models\IntegrationCredential;
use App\Models\Store;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Tests\TestCase;

class IngestEventsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_signed_event_batch_is_committed_to_inbox(): void
    {
        [$integration, $credential, $secret] = $this->integrationCredential();
        $event = $this->event('33333333-3333-4333-8333-333333333333');
        $body = json_encode(['events' => [$event]], JSON_THROW_ON_ERROR);

        $this->callSignedEvents($credential->key_id, $secret, $body)
            ->assertAccepted()
            ->assertJsonPath('results.0.status', 'accepted')
            ->assertJsonPath('results.0.event_id', '33333333-3333-4333-8333-333333333333');

        $this->assertDatabaseHas('event_inbox', [
            'tenant_id' => $integration->tenant_id,
            'store_id' => $integration->store_id,
            'integration_id' => $integration->id,
            'provider_event_id' => '33333333-3333-4333-8333-333333333333',
            'schema_version' => '1.0',
            'event_type' => 'order.snapshot',
            'aggregate_type' => 'order',
            'aggregate_external_id' => 'order-1001',
            'status' => 'received',
        ]);
    }

    public function test_identical_duplicate_event_is_reported_as_duplicate(): void
    {
        [, $credential, $secret] = $this->integrationCredential();
        $event = $this->event('33333333-3333-4333-8333-333333333333');
        $body = json_encode(['events' => [$event]], JSON_THROW_ON_ERROR);

        $this->callSignedEvents($credential->key_id, $secret, $body, nonce: '550e8400-e29b-41d4-a716-446655440000')
            ->assertAccepted()
            ->assertJsonPath('results.0.status', 'accepted');
        $this->callSignedEvents($credential->key_id, $secret, $body, nonce: '550e8400-e29b-41d4-a716-446655440001')
            ->assertAccepted()
            ->assertJsonPath('results.0.status', 'duplicate');

        $this->assertSame(1, \App\Models\EventInbox::query()->count());
    }

    public function test_same_event_id_with_different_payload_is_conflict(): void
    {
        [, $credential, $secret] = $this->integrationCredential();
        $first = $this->event('33333333-3333-4333-8333-333333333333');
        $second = $this->event('33333333-3333-4333-8333-333333333333', aggregateId: 'order-1002');

        $this->callSignedEvents($credential->key_id, $secret, json_encode(['events' => [$first]], JSON_THROW_ON_ERROR), nonce: '550e8400-e29b-41d4-a716-446655440000')
            ->assertAccepted();
        $this->callSignedEvents($credential->key_id, $secret, json_encode(['events' => [$second]], JSON_THROW_ON_ERROR), nonce: '550e8400-e29b-41d4-a716-446655440001')
            ->assertStatus(207)
            ->assertJsonPath('results.0.status', 'conflict')
            ->assertJsonPath('results.0.code', 'event_id_conflict');
    }

    public function test_mixed_batch_returns_207_with_per_record_results(): void
    {
        [, $credential, $secret] = $this->integrationCredential();
        $valid = $this->event('33333333-3333-4333-8333-333333333333');
        $invalid = $valid;
        unset($invalid['aggregate_id']);
        $body = json_encode(['events' => [$valid, $invalid]], JSON_THROW_ON_ERROR);

        $this->callSignedEvents($credential->key_id, $secret, $body)
            ->assertStatus(207)
            ->assertJsonPath('results.0.status', 'accepted')
            ->assertJsonPath('results.1.status', 'invalid')
            ->assertJsonPath('results.1.code', 'schema_invalid');

        $this->assertSame(1, \App\Models\EventInbox::query()->count());
    }

    public function test_invalid_batch_envelope_rejects_without_commit(): void
    {
        [, $credential, $secret] = $this->integrationCredential();
        $body = '{"events":[]}';

        $this->callSignedEvents($credential->key_id, $secret, $body)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'schema_invalid');

        $this->assertSame(0, \App\Models\EventInbox::query()->count());
    }

    public function test_unsupported_schema_version_is_per_record_invalid(): void
    {
        [, $credential, $secret] = $this->integrationCredential();
        $event = $this->event('33333333-3333-4333-8333-333333333333');
        $event['schema_version'] = '2.0';
        $body = json_encode(['events' => [$event]], JSON_THROW_ON_ERROR);

        $this->callSignedEvents($credential->key_id, $secret, $body)
            ->assertStatus(207)
            ->assertJsonPath('results.0.status', 'invalid')
            ->assertJsonPath('results.0.code', 'schema_unsupported');
    }

    /**
     * @return array{0: Integration, 1: IntegrationCredential, 2: string}
     */
    private function integrationCredential(): array
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
            'status' => IntegrationCredential::STATUS_ACTIVE,
            'created_at' => now(),
        ]);

        return [$integration, $credential, $secret];
    }

    private function event(string $eventId, string $aggregateId = 'order-1001'): array
    {
        $observedAt = now()->subMinute();

        return [
            'schema_version' => '1.0',
            'event_id' => $eventId,
            'type' => 'order.snapshot',
            'aggregate_type' => 'order',
            'aggregate_id' => $aggregateId,
            'aggregate_revision' => 1,
            'occurred_at' => $observedAt->copy()->subSecond()->toJSON(),
            'observed_at' => $observedAt->toJSON(),
            'is_synthetic' => false,
            'data' => [
                'status' => 'processing',
                'currency' => 'EUR',
                'currency_exponent' => 2,
                'total_minor' => '18400',
                'payment_expected' => true,
            ],
        ];
    }

    private function callSignedEvents(
        string $keyId,
        string $secret,
        string $body,
        ?string $timestamp = null,
        ?string $nonce = null,
    ): \Illuminate\Testing\TestResponse {
        $timestamp ??= (string) time();
        $nonce ??= (string) Str::uuid();

        return $this->call('POST', '/api/v1/ingest/events', [], [], [], [
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
            '/api/v1/ingest/events',
            hash('sha256', $body),
        ]), $secret);
    }
}
