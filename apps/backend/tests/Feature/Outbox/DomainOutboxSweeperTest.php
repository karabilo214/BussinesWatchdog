<?php

namespace Tests\Feature\Outbox;

use App\Models\DomainOutbox;
use App\Models\Tenant;
use App\Support\Outbox\DomainOutboxSweeper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DomainOutboxSweeperTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_releases_expired_leases_to_pending(): void
    {
        $tenant = $this->tenant();
        $expired = $this->outbox($tenant, 'expired', leaseUntil: now()->subMinute());
        $active = $this->outbox($tenant, 'active', leaseUntil: now()->addMinute());

        $released = app(DomainOutboxSweeper::class)->releaseExpiredLeases();

        $this->assertSame(1, $released);
        $this->assertDatabaseHas('domain_outbox', [
            'id' => $expired->id,
            'status' => DomainOutbox::STATUS_PENDING,
            'lease_until' => null,
            'error_code' => DomainOutboxSweeper::ERROR_LEASE_EXPIRED,
        ]);
        $this->assertDatabaseHas('domain_outbox', [
            'id' => $active->id,
            'status' => DomainOutbox::STATUS_LEASED,
            'error_code' => null,
        ]);
    }

    private function tenant(): Tenant
    {
        return Tenant::query()->create([
            'name' => 'Demo Tenant',
            'timezone' => 'Europe/Kyiv',
        ]);
    }

    private function outbox(Tenant $tenant, string $dedupeKey, mixed $leaseUntil): DomainOutbox
    {
        return DomainOutbox::query()->create([
            'tenant_id' => $tenant->id,
            'topic' => DomainOutbox::TOPIC_EVENT_INBOX_RECEIVED,
            'dedupe_key' => $dedupeKey,
            'payload' => ['event_inbox_id' => fake()->uuid()],
            'status' => DomainOutbox::STATUS_LEASED,
            'attempts' => 1,
            'next_attempt_at' => now()->subMinute(),
            'lease_until' => $leaseUntil,
            'created_at' => now(),
        ]);
    }
}
