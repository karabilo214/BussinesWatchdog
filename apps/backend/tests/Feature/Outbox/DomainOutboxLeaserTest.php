<?php

namespace Tests\Feature\Outbox;

use App\Models\DomainOutbox;
use App\Models\Tenant;
use App\Support\Outbox\DomainOutboxLeaser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DomainOutboxLeaserTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_leases_due_pending_messages_in_order(): void
    {
        $tenant = $this->tenant();
        $first = $this->outbox($tenant, 'first', nextAttemptAt: now()->subMinutes(2), createdAt: now()->subMinutes(2));
        $second = $this->outbox($tenant, 'second', nextAttemptAt: now()->subMinute(), createdAt: now()->subMinute());
        $this->outbox($tenant, 'future', nextAttemptAt: now()->addMinute());

        $leased = app(DomainOutboxLeaser::class)->leaseDue(limit: 2, leaseSeconds: 90);

        $this->assertSame([$first->id, $second->id], $leased->pluck('id')->all());
        $this->assertDatabaseHas('domain_outbox', [
            'id' => $first->id,
            'status' => 'leased',
            'attempts' => 1,
        ]);
        $this->assertDatabaseHas('domain_outbox', [
            'id' => $second->id,
            'status' => 'leased',
            'attempts' => 1,
        ]);
        $this->assertDatabaseHas('domain_outbox', [
            'dedupe_key' => 'future',
            'status' => 'pending',
            'attempts' => 0,
        ]);
        $this->assertTrue($first->refresh()->lease_until->greaterThan(now()));
    }

    public function test_it_does_not_lease_active_leases(): void
    {
        $tenant = $this->tenant();
        $this->outbox(
            $tenant,
            'active-lease',
            status: DomainOutbox::STATUS_LEASED,
            attempts: 1,
            nextAttemptAt: now()->subMinute(),
            leaseUntil: now()->addMinute(),
        );

        $leased = app(DomainOutboxLeaser::class)->leaseDue();

        $this->assertCount(0, $leased);
        $this->assertDatabaseHas('domain_outbox', [
            'dedupe_key' => 'active-lease',
            'status' => 'leased',
            'attempts' => 1,
        ]);
    }

    public function test_it_releases_expired_leases_by_leasing_them_again(): void
    {
        $tenant = $this->tenant();
        $expired = $this->outbox(
            $tenant,
            'expired-lease',
            status: DomainOutbox::STATUS_LEASED,
            attempts: 2,
            nextAttemptAt: now()->subMinutes(5),
            leaseUntil: now()->subMinute(),
        );

        $leased = app(DomainOutboxLeaser::class)->leaseDue();

        $this->assertSame([$expired->id], $leased->pluck('id')->all());
        $this->assertDatabaseHas('domain_outbox', [
            'id' => $expired->id,
            'status' => 'leased',
            'attempts' => 3,
        ]);
        $this->assertTrue($expired->refresh()->lease_until->greaterThan(now()));
    }

    public function test_it_ignores_terminal_messages(): void
    {
        $tenant = $this->tenant();
        $this->outbox($tenant, 'published', status: DomainOutbox::STATUS_PUBLISHED, nextAttemptAt: now()->subMinute());
        $this->outbox($tenant, 'dead', status: DomainOutbox::STATUS_DEAD_LETTER, nextAttemptAt: now()->subMinute());

        $leased = app(DomainOutboxLeaser::class)->leaseDue();

        $this->assertCount(0, $leased);
    }

    private function tenant(): Tenant
    {
        return Tenant::query()->create([
            'name' => 'Demo Tenant',
            'timezone' => 'Europe/Kyiv',
        ]);
    }

    private function outbox(
        Tenant $tenant,
        string $dedupeKey,
        string $status = DomainOutbox::STATUS_PENDING,
        int $attempts = 0,
        mixed $nextAttemptAt = null,
        mixed $leaseUntil = null,
        mixed $createdAt = null,
    ): DomainOutbox {
        return DomainOutbox::query()->create([
            'tenant_id' => $tenant->id,
            'topic' => DomainOutbox::TOPIC_EVENT_INBOX_RECEIVED,
            'dedupe_key' => $dedupeKey,
            'payload' => ['dedupe_key' => $dedupeKey],
            'status' => $status,
            'attempts' => $attempts,
            'next_attempt_at' => $nextAttemptAt ?? now()->subMinute(),
            'lease_until' => $leaseUntil,
            'created_at' => $createdAt ?? now(),
        ]);
    }
}
