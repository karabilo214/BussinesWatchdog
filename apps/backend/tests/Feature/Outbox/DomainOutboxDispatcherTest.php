<?php

namespace Tests\Feature\Outbox;

use App\Models\DomainOutbox;
use App\Models\EventInbox;
use App\Models\Integration;
use App\Models\Store;
use App\Models\Tenant;
use App\Support\Outbox\DomainOutboxDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DomainOutboxDispatcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatcher_publishes_known_due_messages(): void
    {
        [$tenant, $inbox] = $this->receivedEventInbox();
        $message = $this->outbox(
            $tenant,
            DomainOutbox::TOPIC_EVENT_INBOX_RECEIVED,
            ['event_inbox_id' => $inbox->id],
        );

        $result = app(DomainOutboxDispatcher::class)->dispatchDue(limit: 10, leaseSeconds: 60);

        $this->assertSame([
            'leased' => 1,
            'published' => 1,
            'failed' => 0,
        ], $result);
        $this->assertDatabaseHas('domain_outbox', [
            'id' => $message->id,
            'status' => DomainOutbox::STATUS_PUBLISHED,
            'error_code' => null,
        ]);
        $this->assertNotNull($message->refresh()->published_at);
        $this->assertDatabaseHas('event_inbox', [
            'id' => $inbox->id,
            'status' => EventInbox::STATUS_PROCESSED,
            'error_code' => null,
        ]);
        $this->assertNotNull($inbox->refresh()->processed_at);
    }

    public function test_dispatcher_publishes_already_processed_inbox_events_idempotently(): void
    {
        [$tenant, $inbox] = $this->receivedEventInbox(EventInbox::STATUS_PROCESSED);
        $processedAt = $inbox->processed_at;
        $message = $this->outbox(
            $tenant,
            DomainOutbox::TOPIC_EVENT_INBOX_RECEIVED,
            ['event_inbox_id' => $inbox->id],
        );

        $result = app(DomainOutboxDispatcher::class)->dispatchDue(limit: 10, leaseSeconds: 60);

        $this->assertSame([
            'leased' => 1,
            'published' => 1,
            'failed' => 0,
        ], $result);
        $this->assertDatabaseHas('domain_outbox', [
            'id' => $message->id,
            'status' => DomainOutbox::STATUS_PUBLISHED,
        ]);
        $this->assertSame($processedAt?->toJSON(), $inbox->refresh()->processed_at?->toJSON());
    }

    public function test_dispatcher_schedules_retry_for_unsupported_topics(): void
    {
        $tenant = $this->tenant();
        $message = $this->outbox($tenant, 'unsupported.topic');

        $result = app(DomainOutboxDispatcher::class)->dispatchDue(limit: 10, leaseSeconds: 60);

        $this->assertSame([
            'leased' => 1,
            'published' => 0,
            'failed' => 1,
        ], $result);
        $this->assertDatabaseHas('domain_outbox', [
            'id' => $message->id,
            'status' => DomainOutbox::STATUS_PENDING,
            'attempts' => 1,
            'error_code' => 'outbox_topic_unsupported',
        ]);
    }

    public function test_dispatcher_schedules_retry_when_inbox_event_is_missing(): void
    {
        $tenant = $this->tenant();
        $message = $this->outbox(
            $tenant,
            DomainOutbox::TOPIC_EVENT_INBOX_RECEIVED,
            ['event_inbox_id' => fake()->uuid()],
        );

        $result = app(DomainOutboxDispatcher::class)->dispatchDue(limit: 10, leaseSeconds: 60);

        $this->assertSame([
            'leased' => 1,
            'published' => 0,
            'failed' => 1,
        ], $result);
        $this->assertDatabaseHas('domain_outbox', [
            'id' => $message->id,
            'status' => DomainOutbox::STATUS_PENDING,
            'attempts' => 1,
            'error_code' => 'event_inbox_unprocessable',
        ]);
    }

    public function test_console_command_dispatches_due_messages(): void
    {
        [$tenant, $inbox] = $this->receivedEventInbox();
        $this->outbox(
            $tenant,
            DomainOutbox::TOPIC_EVENT_INBOX_RECEIVED,
            ['event_inbox_id' => $inbox->id],
        );

        $this->artisan('outbox:dispatch --limit=5 --lease-seconds=30')
            ->expectsOutputToContain('Outbox dispatch complete: leased=1 published=1 failed=0')
            ->assertSuccessful();
    }

    private function tenant(): Tenant
    {
        return Tenant::query()->create([
            'name' => 'Demo Tenant',
            'timezone' => 'Europe/Kyiv',
        ]);
    }

    /**
     * @return array{0: Tenant, 1: EventInbox}
     */
    private function receivedEventInbox(string $status = EventInbox::STATUS_RECEIVED): array
    {
        $tenant = $this->tenant();
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
            'install_id' => fake()->uuid(),
            'mode' => 'live',
            'source_authority' => Integration::SOURCE_STORE_REPORTED,
            'status' => Integration::STATUS_ACTIVE,
            'capabilities' => [],
            'connector_version' => '1.0.0',
            'health' => [],
        ]);

        $processedAt = $status === EventInbox::STATUS_PROCESSED ? now()->subMinute() : null;
        $inbox = EventInbox::query()->create([
            'tenant_id' => $tenant->id,
            'store_id' => $store->id,
            'integration_id' => $integration->id,
            'provider_event_id' => fake()->uuid(),
            'schema_version' => '1.0',
            'event_type' => 'order.snapshot',
            'aggregate_type' => 'order',
            'aggregate_external_id' => 'order-1001',
            'aggregate_revision' => 1,
            'occurred_at' => now()->subMinute(),
            'observed_at' => now()->subMinute(),
            'received_at' => now(),
            'is_synthetic' => false,
            'payload' => [],
            'payload_hash' => hash('sha256', fake()->uuid()),
            'canonicalization_version' => 1,
            'status' => $status,
            'attempt_count' => 0,
            'next_attempt_at' => now(),
            'processed_at' => $processedAt,
            'request_id' => fake()->uuid(),
        ]);

        return [$tenant, $inbox];
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function outbox(Tenant $tenant, string $topic, ?array $payload = null): DomainOutbox
    {
        return DomainOutbox::query()->create([
            'tenant_id' => $tenant->id,
            'topic' => $topic,
            'dedupe_key' => fake()->uuid(),
            'payload' => $payload ?? ['event_inbox_id' => fake()->uuid()],
            'status' => DomainOutbox::STATUS_PENDING,
            'attempts' => 0,
            'next_attempt_at' => now()->subMinute(),
            'created_at' => now(),
        ]);
    }
}
