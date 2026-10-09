<?php

namespace Tests\Feature\Ingest;

use App\Http\Controllers\Api\V1\Ingest\EventsController;
use App\Models\DomainOutbox;
use App\Models\EventInbox;
use App\Models\Integration;
use App\Models\IntegrationCredential;
use App\Models\Store;
use App\Models\Tenant;
use App\Support\Ingest\EventSchemaValidator;
use App\Support\Ingest\EventValidationResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
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
            ->assertJsonPath('results.0.status', EventsController::RESULT_ACCEPTED)
            ->assertJsonPath('results.0.event_id', '33333333-3333-4333-8333-333333333333');

        $this->assertDatabaseHas('event_inbox', [
            'tenant_id' => $integration->tenant_id,
            'store_id' => $integration->store_id,
            'integration_id' => $integration->id,
            'provider_event_id' => '33333333-3333-4333-8333-333333333333',
            'schema_version' => '1.0',
            'event_type' => EventInbox::EVENT_ORDER_SNAPSHOT,
            'aggregate_type' => EventInbox::AGGREGATE_ORDER,
            'aggregate_external_id' => 'order-1001',
            'status' => 'received',
        ]);
        $this->assertDatabaseHas('domain_outbox', [
            'tenant_id' => $integration->tenant_id,
            'topic' => 'event_inbox.received',
            'status' => 'pending',
        ]);
    }

    public function test_identical_duplicate_event_is_reported_as_duplicate(): void
    {
        [, $credential, $secret] = $this->integrationCredential();
        $event = $this->event('33333333-3333-4333-8333-333333333333');
        $body = json_encode(['events' => [$event]], JSON_THROW_ON_ERROR);

        $this->callSignedEvents($credential->key_id, $secret, $body, nonce: '550e8400-e29b-41d4-a716-446655440000')
            ->assertAccepted()
            ->assertJsonPath('results.0.status', EventsController::RESULT_ACCEPTED);
        $this->callSignedEvents($credential->key_id, $secret, $body, nonce: '550e8400-e29b-41d4-a716-446655440001')
            ->assertAccepted()
            ->assertJsonPath('results.0.status', EventsController::RESULT_DUPLICATE);

        $this->assertSame(1, EventInbox::query()->count());
        $this->assertSame(1, DomainOutbox::query()->count());
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
            ->assertJsonPath('results.0.status', EventsController::RESULT_CONFLICT)
            ->assertJsonPath('results.0.code', EventsController::ERROR_EVENT_ID_CONFLICT);
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
            ->assertJsonPath('results.0.status', EventsController::RESULT_ACCEPTED)
            ->assertJsonPath('results.1.status', EventsController::RESULT_INVALID)
            ->assertJsonPath('results.1.code', EventValidationResult::ERROR_SCHEMA_INVALID);

        $this->assertSame(1, EventInbox::query()->count());
        $this->assertSame(1, DomainOutbox::query()->count());
    }

    public function test_invalid_batch_envelope_rejects_without_commit(): void
    {
        [, $credential, $secret] = $this->integrationCredential();
        $body = '{"events":[]}';

        $this->callSignedEvents($credential->key_id, $secret, $body)
            ->assertUnprocessable()
            ->assertJsonPath('code', EventValidationResult::ERROR_SCHEMA_INVALID);

        $this->assertSame(0, EventInbox::query()->count());
    }

    public function test_malformed_json_rejects_without_commit(): void
    {
        [, $credential, $secret] = $this->integrationCredential();
        $body = '{"events":[';

        $this->callSignedEvents($credential->key_id, $secret, $body)
            ->assertBadRequest()
            ->assertJsonPath('code', EventsController::ERROR_MALFORMED_JSON);

        $this->assertSame(0, EventInbox::query()->count());
    }

    public function test_oversized_batch_rejects_without_commit(): void
    {
        [, $credential, $secret] = $this->integrationCredential();
        $body = json_encode([
            'events' => [$this->event('33333333-3333-4333-8333-333333333333')],
            'padding' => str_repeat('x', 1048576),
        ], JSON_THROW_ON_ERROR);

        $this->callSignedEvents($credential->key_id, $secret, $body)
            ->assertStatus(413)
            ->assertJsonPath('code', EventsController::ERROR_REQUEST_TOO_LARGE);

        $this->assertSame(0, EventInbox::query()->count());
    }

    public function test_unsupported_schema_version_is_quarantined_without_outbox(): void
    {
        [, $credential, $secret] = $this->integrationCredential();
        $event = $this->event('33333333-3333-4333-8333-333333333333');
        $event['schema_version'] = '2.0';
        $body = json_encode(['events' => [$event]], JSON_THROW_ON_ERROR);

        $this->callSignedEvents($credential->key_id, $secret, $body)
            ->assertStatus(207)
            ->assertJsonPath('results.0.status', EventsController::RESULT_QUARANTINED)
            ->assertJsonPath('results.0.code', EventValidationResult::ERROR_SCHEMA_UNSUPPORTED);

        $this->assertDatabaseHas('event_inbox', [
            'provider_event_id' => '33333333-3333-4333-8333-333333333333',
            'status' => EventInbox::STATUS_QUARANTINED,
            'error_code' => EventValidationResult::ERROR_SCHEMA_UNSUPPORTED,
        ]);
        $this->assertSame(0, DomainOutbox::query()->count());
    }

    public function test_event_data_contract_violation_is_quarantined_without_outbox(): void
    {
        [, $credential, $secret] = $this->integrationCredential();
        $event = $this->event('33333333-3333-4333-8333-333333333333');
        $event['data']['unexpected'] = 'not in contract';
        $body = json_encode(['events' => [$event]], JSON_THROW_ON_ERROR);

        $this->callSignedEvents($credential->key_id, $secret, $body)
            ->assertStatus(207)
            ->assertJsonPath('results.0.status', EventsController::RESULT_QUARANTINED)
            ->assertJsonPath('results.0.code', EventValidationResult::ERROR_SCHEMA_INVALID);

        $this->assertDatabaseHas('event_inbox', [
            'provider_event_id' => '33333333-3333-4333-8333-333333333333',
            'status' => EventInbox::STATUS_QUARANTINED,
            'error_code' => EventValidationResult::ERROR_SCHEMA_INVALID,
        ]);
        $this->assertSame(0, DomainOutbox::query()->count());
    }

    /**
     * @return array<string, array{0: string, 1: mixed}>
     */
    public static function schemaOnlyViolations(): array
    {
        return [
            'empty display number' => ['display_number', ''],
            'date without time' => ['source_created_at', '2026-10-09'],
        ];
    }

    #[DataProvider('schemaOnlyViolations')]
    public function test_json_schema_violations_are_quarantined(string $field, mixed $value): void
    {
        [, $credential, $secret] = $this->integrationCredential();
        $event = $this->event('44444444-4444-4444-8444-444444444444');
        $event['data'][$field] = $value;
        $body = json_encode(['events' => [$event]], JSON_THROW_ON_ERROR);

        $this->callSignedEvents($credential->key_id, $secret, $body)
            ->assertStatus(207)
            ->assertJsonPath('results.0.status', EventsController::RESULT_QUARANTINED)
            ->assertJsonPath('results.0.code', EventValidationResult::ERROR_SCHEMA_INVALID);

        $this->assertSame(0, DomainOutbox::query()->count());
    }

    public function test_non_object_json_body_is_rejected_without_a_server_error(): void
    {
        [, $credential, $secret] = $this->integrationCredential();

        $this->callSignedEvents($credential->key_id, $secret, '[1,2,3]')
            ->assertUnprocessable()
            ->assertJsonPath('code', EventValidationResult::ERROR_SCHEMA_INVALID);
    }

    public function test_connector_credentials_cannot_submit_independent_provider_evidence(): void
    {
        [, $credential, $secret] = $this->integrationCredential();
        $independent = $this->paymentEvent('44444444-4444-4444-8444-444444444441', 'independent_provider');
        $storeReported = $this->paymentEvent('44444444-4444-4444-8444-444444444442', 'store_reported');
        $body = json_encode(['events' => [$independent, $storeReported]], JSON_THROW_ON_ERROR);

        $this->callSignedEvents($credential->key_id, $secret, $body)
            ->assertStatus(207)
            ->assertJsonPath('results.0.status', EventsController::RESULT_INVALID)
            ->assertJsonPath('results.0.code', EventsController::ERROR_SOURCE_AUTHORITY_NOT_PERMITTED)
            ->assertJsonPath('results.1.status', EventsController::RESULT_ACCEPTED);

        $this->assertDatabaseMissing('event_inbox', ['provider_event_id' => '44444444-4444-4444-8444-444444444441']);
        $this->assertSame(1, DomainOutbox::query()->count());
    }

    public function test_backend_schema_copy_matches_the_contract(): void
    {
        $contract = base_path('../../contracts/event.schema.json');

        if (! is_file($contract)) {
            $this->markTestSkipped('Repository contracts directory is not mounted.');
        }

        $this->assertJsonFileEqualsJsonFile($contract, EventSchemaValidator::schemaPath());
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
            'type' => EventInbox::EVENT_ORDER_SNAPSHOT,
            'aggregate_type' => EventInbox::AGGREGATE_ORDER,
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

    /**
     * @return array<string, mixed>
     */
    private function paymentEvent(string $eventId, string $authority): array
    {
        return [
            'schema_version' => '1.0',
            'event_id' => $eventId,
            'type' => EventInbox::EVENT_PAYMENT_SNAPSHOT,
            'aggregate_type' => EventInbox::AGGREGATE_PAYMENT,
            'aggregate_id' => 'payment-'.$eventId,
            'occurred_at' => now()->subMinutes(2)->toJSON(),
            'observed_at' => now()->subMinute()->toJSON(),
            'is_synthetic' => false,
            'data' => [
                'intent_ref' => 'pi_demo_1001',
                'charge_ref' => null,
                'mode' => 'live',
                'currency' => 'EUR',
                'currency_exponent' => 2,
                'status' => 'captured',
                'source_updated_at' => now()->subMinute()->toJSON(),
                'source_authority' => $authority,
            ],
        ];
    }

    private function callSignedEvents(
        string $keyId,
        string $secret,
        string $body,
        ?string $timestamp = null,
        ?string $nonce = null,
    ): TestResponse {
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
