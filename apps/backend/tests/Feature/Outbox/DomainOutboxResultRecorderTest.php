<?php

namespace Tests\Feature\Outbox;

use App\Models\DomainOutbox;
use App\Models\Tenant;
use App\Support\Outbox\DomainOutboxResultRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DomainOutboxResultRecorderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_marks_active_lease_as_published(): void
    {
        $message = $this->outbox(attempts: 1);

        $recorded = app(DomainOutboxResultRecorder::class)->markPublished($message->id);

        $this->assertTrue($recorded);
        $message->refresh();
        $this->assertSame(DomainOutbox::STATUS_PUBLISHED, $message->status);
        $this->assertNull($message->lease_until);
        $this->assertNull($message->error_code);
        $this->assertNotNull($message->published_at);
    }

    public function test_it_schedules_retry_for_failed_active_lease_before_max_attempts(): void
    {
        $message = $this->outbox(attempts: 2);

        $recorded = app(DomainOutboxResultRecorder::class)->markFailed(
            $message->id,
            'temporary_failure',
            maxAttempts: 5,
            retryDelaySeconds: 120,
        );

        $this->assertTrue($recorded);
        $message->refresh();
        $this->assertSame(DomainOutbox::STATUS_PENDING, $message->status);
        $this->assertSame('temporary_failure', $message->error_code);
        $this->assertNull($message->lease_until);
        $this->assertTrue($message->next_attempt_at->greaterThan(now()->addSeconds(90)));
    }

    public function test_it_uses_exponential_backoff_with_jitter_by_default(): void
    {
        $message = $this->outbox(attempts: 3);
        $before = now();

        $recorded = app(DomainOutboxResultRecorder::class)->markFailed(
            $message->id,
            'temporary_failure',
            maxAttempts: 5,
        );

        $this->assertTrue($recorded);
        $message->refresh();
        $this->assertSame(DomainOutbox::STATUS_PENDING, $message->status);
        $this->assertTrue($message->next_attempt_at->betweenIncluded(
            $before->copy()->addSeconds(96),
            $before->copy()->addSeconds(144),
        ));
    }

    public function test_it_dead_letters_failed_active_lease_at_max_attempts(): void
    {
        $message = $this->outbox(attempts: 5);

        $recorded = app(DomainOutboxResultRecorder::class)->markFailed(
            $message->id,
            'permanent_failure',
            maxAttempts: 5,
        );

        $this->assertTrue($recorded);
        $message->refresh();
        $this->assertSame(DomainOutbox::STATUS_DEAD_LETTER, $message->status);
        $this->assertSame('permanent_failure', $message->error_code);
        $this->assertNull($message->lease_until);
    }

    public function test_it_rejects_stale_lease_results(): void
    {
        $message = $this->outbox(leaseUntil: now()->subMinute());

        $published = app(DomainOutboxResultRecorder::class)->markPublished($message->id);
        $failed = app(DomainOutboxResultRecorder::class)->markFailed($message->id, 'late_result');

        $this->assertFalse($published);
        $this->assertFalse($failed);
        $this->assertDatabaseHas('domain_outbox', [
            'id' => $message->id,
            'status' => DomainOutbox::STATUS_LEASED,
            'error_code' => null,
        ]);
    }

    public function test_it_rejects_non_leased_messages(): void
    {
        $message = $this->outbox(status: DomainOutbox::STATUS_PENDING, leaseUntil: null);

        $recorded = app(DomainOutboxResultRecorder::class)->markPublished($message->id);

        $this->assertFalse($recorded);
        $this->assertDatabaseHas('domain_outbox', [
            'id' => $message->id,
            'status' => DomainOutbox::STATUS_PENDING,
            'published_at' => null,
        ]);
    }

    private function outbox(
        string $status = DomainOutbox::STATUS_LEASED,
        int $attempts = 1,
        mixed $leaseUntil = null,
    ): DomainOutbox {
        $tenant = Tenant::query()->create([
            'name' => 'Demo Tenant',
            'timezone' => 'Europe/Kyiv',
        ]);

        return DomainOutbox::query()->create([
            'tenant_id' => $tenant->id,
            'topic' => DomainOutbox::TOPIC_EVENT_INBOX_RECEIVED,
            'dedupe_key' => fake()->uuid(),
            'payload' => ['event_inbox_id' => fake()->uuid()],
            'status' => $status,
            'attempts' => $attempts,
            'next_attempt_at' => now()->subMinute(),
            'lease_until' => $leaseUntil ?? now()->addMinute(),
            'created_at' => now(),
        ]);
    }
}
