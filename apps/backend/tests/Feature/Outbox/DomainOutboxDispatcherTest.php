<?php

namespace Tests\Feature\Outbox;

use App\Models\DomainOutbox;
use App\Models\EventInbox;
use App\Models\Integration;
use App\Models\Order;
use App\Models\OrderRevision;
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

    public function test_dispatcher_projects_order_snapshot_before_publishing_outbox(): void
    {
        [$tenant, $inbox] = $this->receivedEventInbox();
        $message = $this->outbox(
            $tenant,
            DomainOutbox::TOPIC_EVENT_INBOX_RECEIVED,
            ['event_inbox_id' => $inbox->id],
        );

        app(DomainOutboxDispatcher::class)->dispatchDue(limit: 10, leaseSeconds: 60);

        $this->assertDatabaseHas('orders', [
            'tenant_id' => $inbox->tenant_id,
            'store_id' => $inbox->store_id,
            'integration_id' => $inbox->integration_id,
            'external_id' => 'order-1001',
            'source_revision' => 1,
            'status' => 'processing',
            'currency' => 'EUR',
            'currency_exponent' => 2,
            'total_minor' => 18400,
            'payment_expected' => true,
            'gateway' => 'stripe',
            'transaction_ref' => 'pi_demo_1001',
            'financial_support' => 'supported',
        ]);
        $order = Order::query()->where('external_id', 'order-1001')->firstOrFail();
        $this->assertDatabaseHas('order_revisions', [
            'tenant_id' => $inbox->tenant_id,
            'store_id' => $inbox->store_id,
            'order_id' => $order->id,
            'event_id' => $inbox->id,
            'source_revision' => 1,
            'payload_hash' => $inbox->payload_hash,
        ]);
        $this->assertSame(DomainOutbox::STATUS_PUBLISHED, $message->refresh()->status);
    }

    public function test_stale_order_snapshot_revision_does_not_overwrite_current_order(): void
    {
        [$tenant, $store, $integration] = $this->integrationContext();
        $latest = $this->receivedEventInbox(
            context: [$tenant, $store, $integration],
            sourceRevision: 2,
            totalMinor: '25000',
            status: 'completed',
        )[1];
        $stale = $this->receivedEventInbox(
            context: [$tenant, $store, $integration],
            sourceRevision: 1,
            totalMinor: '18400',
            status: 'processing',
        )[1];
        $this->outbox($tenant, DomainOutbox::TOPIC_EVENT_INBOX_RECEIVED, ['event_inbox_id' => $latest->id]);
        $this->outbox($tenant, DomainOutbox::TOPIC_EVENT_INBOX_RECEIVED, ['event_inbox_id' => $stale->id]);

        app(DomainOutboxDispatcher::class)->dispatchDue(limit: 10, leaseSeconds: 60);

        $order = Order::query()->where('integration_id', $integration->id)->where('external_id', 'order-1001')->firstOrFail();
        $this->assertSame(2, $order->source_revision);
        $this->assertSame(25000, $order->total_minor);
        $this->assertSame('completed', $order->status);
        $this->assertSame(2, OrderRevision::query()->where('order_id', $order->id)->count());
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
     *
     * @param array{0: Tenant, 1: Store, 2: Integration}|null $context
     */
    private function receivedEventInbox(
        string $inboxStatus = EventInbox::STATUS_RECEIVED,
        ?array $context = null,
        int $sourceRevision = 1,
        string $totalMinor = '18400',
        string $status = 'processing',
    ): array
    {
        [$tenant, $store, $integration] = $context ?? $this->integrationContext();
        $processedAt = $inboxStatus === EventInbox::STATUS_PROCESSED ? now()->subMinute() : null;
        $payload = [
            'schema_version' => '1.0',
            'event_id' => fake()->uuid(),
            'type' => 'order.snapshot',
            'aggregate_type' => 'order',
            'aggregate_id' => 'order-1001',
            'aggregate_revision' => $sourceRevision,
            'occurred_at' => now()->subMinute()->toJSON(),
            'observed_at' => now()->toJSON(),
            'is_synthetic' => false,
            'data' => [
                'status' => $status,
                'display_number' => '#1001',
                'currency' => 'EUR',
                'currency_exponent' => 2,
                'total_minor' => $totalMinor,
                'gateway' => 'stripe',
                'transaction_ref' => 'pi_demo_1001',
                'payment_expected' => true,
                'paid_marked_at' => now()->subSeconds(30)->toJSON(),
                'source_created_at' => now()->subMinutes(2)->toJSON(),
                'source_updated_at' => now()->subMinute()->toJSON(),
                'mode' => 'live',
                'financial_support' => 'supported',
            ],
        ];
        $inbox = EventInbox::query()->create([
            'tenant_id' => $tenant->id,
            'store_id' => $store->id,
            'integration_id' => $integration->id,
            'provider_event_id' => fake()->uuid(),
            'schema_version' => '1.0',
            'event_type' => $payload['type'],
            'aggregate_type' => $payload['aggregate_type'],
            'aggregate_external_id' => $payload['aggregate_id'],
            'aggregate_revision' => $sourceRevision,
            'occurred_at' => now()->subMinute(),
            'observed_at' => now(),
            'received_at' => now(),
            'is_synthetic' => false,
            'payload' => $payload,
            'payload_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
            'canonicalization_version' => 1,
            'status' => $inboxStatus,
            'attempt_count' => 0,
            'next_attempt_at' => now(),
            'processed_at' => $processedAt,
            'request_id' => fake()->uuid(),
        ]);

        return [$tenant, $inbox];
    }

    /**
     * @return array{0: Tenant, 1: Store, 2: Integration}
     */
    private function integrationContext(): array
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

        return [$tenant, $store, $integration];
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
