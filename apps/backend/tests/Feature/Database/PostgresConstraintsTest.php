<?php

namespace Tests\Feature\Database;

use App\Models\EventInbox;
use App\Models\Incident;
use App\Models\Integration;
use App\Models\Order;
use App\Models\OrderRevision;
use App\Models\Store;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Notifications\NotificationTestContext;
use Tests\TestCase;

class PostgresConstraintsTest extends TestCase
{
    use NotificationTestContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL-only constraint test; run `make backend-test-pgsql`.');
        }
    }

    public function test_projection_event_reference_must_belong_to_the_same_tenant_and_store(): void
    {
        $context = $this->context();
        $foreign = $this->context();
        $order = $this->order($context);
        $foreignEvent = $this->event($foreign['tenant'], $foreign['store'], $foreign['integration']);

        $this->expectException(QueryException::class);

        OrderRevision::query()->create($this->revision($order, $foreignEvent->id));
    }

    public function test_deleting_an_inbox_event_only_clears_the_event_reference(): void
    {
        $context = $this->context();
        $order = $this->order($context);
        $event = $this->event($context['tenant'], $context['store'], $context['integration']);
        $revision = OrderRevision::query()->create($this->revision($order, $event->id));

        $event->delete();

        $revision->refresh();
        $this->assertNull($revision->event_id);
        $this->assertSame($context['tenant']->id, $revision->tenant_id);
        $this->assertSame($context['store']->id, $revision->store_id);
    }

    public function test_check_constraints_reject_invalid_states(): void
    {
        $context = $this->context();

        foreach ([
            fn () => DB::table('incidents')->insert($this->incidentRow($context['tenant'], $context['store'], ['state' => 'closed'])),
            fn () => DB::table('incidents')->insert($this->incidentRow($context['tenant'], $context['store'], ['currency' => 'eur'])),
            fn () => DB::table('stores')->where('id', $context['store']->id)->update(['status' => 'unknown']),
        ] as $violation) {
            try {
                DB::transaction($violation);
                $this->fail('Expected a CHECK constraint violation.');
            } catch (QueryException $exception) {
                $this->assertSame('23514', $exception->getCode());
            }
        }
    }

    public function test_only_one_active_incident_per_fingerprint(): void
    {
        $context = $this->context();
        DB::table('incidents')->insert($this->incidentRow($context['tenant'], $context['store'], ['fingerprint' => 'fp-1']));
        DB::table('incidents')->insert($this->incidentRow($context['tenant'], $context['store'], ['fingerprint' => 'fp-1', 'state' => Incident::STATE_RESOLVED, 'resolved_at' => now()]));

        $this->expectException(QueryException::class);

        DB::table('incidents')->insert($this->incidentRow($context['tenant'], $context['store'], ['fingerprint' => 'fp-1', 'state' => Incident::STATE_ACKNOWLEDGED]));
    }

    private function event(Tenant $tenant, Store $store, Integration $integration): EventInbox
    {
        $payload = ['type' => EventInbox::EVENT_ORDER_SNAPSHOT, 'aggregate_type' => EventInbox::AGGREGATE_ORDER, 'aggregate_id' => 'order-'.Str::random(6)];

        return EventInbox::query()->create([
            'tenant_id' => $tenant->id,
            'store_id' => $store->id,
            'integration_id' => $integration->id,
            'provider_event_id' => fake()->uuid(),
            'schema_version' => '1.0',
            'event_type' => $payload['type'],
            'aggregate_type' => $payload['aggregate_type'],
            'aggregate_external_id' => $payload['aggregate_id'],
            'aggregate_revision' => 1,
            'occurred_at' => now()->subMinute(),
            'observed_at' => now(),
            'received_at' => now(),
            'is_synthetic' => false,
            'payload' => $payload,
            'payload_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
            'canonicalization_version' => 1,
            'status' => EventInbox::STATUS_PROCESSED,
            'attempt_count' => 0,
            'next_attempt_at' => now(),
            'request_id' => fake()->uuid(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function revision(Order $order, string $eventId): array
    {
        return [
            'tenant_id' => $order->tenant_id,
            'store_id' => $order->store_id,
            'order_id' => $order->id,
            'event_id' => $eventId,
            'source_revision' => 1,
            'snapshot' => [],
            'payload_hash' => hash('sha256', $eventId),
            'observed_at' => now(),
            'created_at' => now(),
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function incidentRow(Tenant $tenant, Store $store, array $overrides = []): array
    {
        return array_merge([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenant->id,
            'store_id' => $store->id,
            'family' => 'money',
            'component' => 'capture',
            'fingerprint' => 'fp-'.Str::random(8),
            'state' => Incident::STATE_OPEN,
            'severity' => Incident::SEVERITY_WARNING,
            'title_code' => 'MONEY_CAPTURE_MISSING',
            'currency' => 'EUR',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides);
    }
}
