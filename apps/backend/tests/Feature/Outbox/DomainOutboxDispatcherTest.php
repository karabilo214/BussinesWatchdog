<?php

namespace Tests\Feature\Outbox;

use App\Models\DomainOutbox;
use App\Models\Tenant;
use App\Support\Outbox\DomainOutboxDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DomainOutboxDispatcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatcher_publishes_known_due_messages(): void
    {
        $message = $this->outbox(DomainOutbox::TOPIC_EVENT_INBOX_RECEIVED);

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
    }

    public function test_dispatcher_schedules_retry_for_unsupported_topics(): void
    {
        $message = $this->outbox('unsupported.topic');

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

    public function test_console_command_dispatches_due_messages(): void
    {
        $this->outbox(DomainOutbox::TOPIC_EVENT_INBOX_RECEIVED);

        $this->artisan('outbox:dispatch --limit=5 --lease-seconds=30')
            ->expectsOutputToContain('Outbox dispatch complete: leased=1 published=1 failed=0')
            ->assertSuccessful();
    }

    private function outbox(string $topic): DomainOutbox
    {
        $tenant = Tenant::query()->create([
            'name' => 'Demo Tenant',
            'timezone' => 'Europe/Kyiv',
        ]);

        return DomainOutbox::query()->create([
            'tenant_id' => $tenant->id,
            'topic' => $topic,
            'dedupe_key' => fake()->uuid(),
            'payload' => ['event_inbox_id' => fake()->uuid()],
            'status' => DomainOutbox::STATUS_PENDING,
            'attempts' => 0,
            'next_attempt_at' => now()->subMinute(),
            'created_at' => now(),
        ]);
    }
}
