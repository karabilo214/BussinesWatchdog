<?php

namespace Tests\Feature\Incidents;

use App\Exceptions\Incidents\IncidentActionRejected;
use App\Models\Incident;
use App\Models\IncidentActivity;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Incidents\IncidentLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class IncidentLifecycleServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_acknowledges_an_open_incident(): void
    {
        $incident = $this->incident();
        $service = app(IncidentLifecycleService::class);

        $acknowledged = $service->acknowledge($incident, null);

        $this->assertSame(Incident::STATE_ACKNOWLEDGED, $acknowledged->state);
        $this->assertNotNull($acknowledged->acknowledged_at);
        $this->assertSame(2, $acknowledged->revision);
        $this->assertDatabaseHas('incident_activity', [
            'incident_id' => $incident->id,
            'kind' => IncidentActivity::KIND_ACKNOWLEDGED,
        ]);
    }

    public function test_acknowledging_twice_is_idempotent_and_does_not_bump_revision_again(): void
    {
        $incident = $this->incident();
        $service = app(IncidentLifecycleService::class);

        $service->acknowledge($incident, null);
        $second = $service->acknowledge($incident->fresh(), null);

        $this->assertSame(2, $second->revision);
        $this->assertSame(1, IncidentActivity::query()->where('kind', IncidentActivity::KIND_ACKNOWLEDGED)->count());
    }

    public function test_it_cannot_acknowledge_a_resolved_incident(): void
    {
        $incident = $this->incident();
        $service = app(IncidentLifecycleService::class);
        $service->resolve($incident, 'Fixed manually.', null);

        $this->expectException(IncidentActionRejected::class);
        $this->expectExceptionMessage('incident_resolved_cannot_acknowledge');

        $service->acknowledge($incident->fresh(), null);
    }

    public function test_it_resolves_an_incident_with_a_reason(): void
    {
        $incident = $this->incident();
        $service = app(IncidentLifecycleService::class);

        $resolved = $service->resolve($incident, 'Manually matched the missing capture.', null);

        $this->assertSame(Incident::STATE_RESOLVED, $resolved->state);
        $this->assertSame('Manually matched the missing capture.', $resolved->resolution_reason);
        $this->assertDatabaseHas('incident_activity', [
            'incident_id' => $incident->id,
            'kind' => IncidentActivity::KIND_RESOLVED,
        ]);
    }

    public function test_it_rejects_resolving_an_already_resolved_incident(): void
    {
        $incident = $this->incident();
        $service = app(IncidentLifecycleService::class);
        $service->resolve($incident, 'First resolution.', null);

        $this->expectException(IncidentActionRejected::class);
        $this->expectExceptionMessage('incident_already_resolved');

        $service->resolve($incident->fresh(), 'Second resolution.', null);
    }

    public function test_it_requires_a_reason_to_resolve(): void
    {
        $incident = $this->incident();
        $service = app(IncidentLifecycleService::class);

        $this->expectException(IncidentActionRejected::class);
        $this->expectExceptionMessage('incident_reason_required');

        $service->resolve($incident, '   ', null);
    }

    public function test_comment_does_not_change_incident_revision_or_seen_times(): void
    {
        $incident = $this->incident();
        $service = app(IncidentLifecycleService::class);

        $activity = $service->comment($incident, 'Looking into this now.', null);

        $this->assertSame(IncidentActivity::KIND_COMMENT, $activity->kind);
        $this->assertSame(1, $incident->fresh()->revision);
        $this->assertSame('Looking into this now.', $activity->sanitized_data['text']);
    }

    public function test_it_snoozes_an_incident_by_creating_a_suppression(): void
    {
        $incident = $this->incident();
        $service = app(IncidentLifecycleService::class);

        $suppression = $service->snooze($incident, now()->addDays(3), 'Known upstream issue.', $this->actor()->id);

        $this->assertSame($incident->id, $suppression->incident_id);
        $this->assertNull($suppression->revoked_at);
        $this->assertDatabaseHas('incident_activity', [
            'incident_id' => $incident->id,
            'kind' => IncidentActivity::KIND_SUPPRESSED,
        ]);
    }

    public function test_it_rejects_a_snooze_window_longer_than_30_days(): void
    {
        $incident = $this->incident();
        $service = app(IncidentLifecycleService::class);

        $this->expectException(IncidentActionRejected::class);
        $this->expectExceptionMessage('suppression_window_too_long');

        $service->snooze($incident, now()->addDays(31), 'Too long.', $this->actor()->id);
    }

    public function test_it_rejects_a_snooze_window_in_the_past(): void
    {
        $incident = $this->incident();
        $service = app(IncidentLifecycleService::class);

        $this->expectException(IncidentActionRejected::class);
        $this->expectExceptionMessage('suppression_window_invalid');

        $service->snooze($incident, now()->subHour(), 'Already past.', $this->actor()->id);
    }

    public function test_it_revokes_a_suppression(): void
    {
        $incident = $this->incident();
        $service = app(IncidentLifecycleService::class);
        $suppression = $service->snooze($incident, now()->addDays(3), 'Known upstream issue.', $this->actor()->id);

        $revoked = $service->revokeSuppression($suppression);

        $this->assertNotNull($revoked->revoked_at);
    }

    public function test_it_rejects_revoking_an_already_revoked_suppression(): void
    {
        $incident = $this->incident();
        $service = app(IncidentLifecycleService::class);
        $suppression = $service->snooze($incident, now()->addDays(3), 'Known upstream issue.', $this->actor()->id);
        $service->revokeSuppression($suppression);

        $this->expectException(IncidentActionRejected::class);
        $this->expectExceptionMessage('suppression_already_revoked');

        $service->revokeSuppression($suppression->fresh());
    }

    private function actor(): User
    {
        return User::query()->create([
            'name' => 'Incident Actor',
            'email' => fake()->unique()->safeEmail(),
            'password_hash' => Hash::make('very-secure-password'),
            'locale' => 'ru',
        ]);
    }

    private function incident(): Incident
    {
        $tenant = Tenant::query()->create([
            'name' => fake()->company(),
            'timezone' => 'Europe/Kyiv',
        ]);
        $store = Store::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Main Shop',
            'base_url' => 'https://'.fake()->domainName(),
            'timezone' => 'Europe/Kyiv',
            'default_currency' => 'EUR',
        ]);

        $now = now();

        return Incident::query()->create([
            'tenant_id' => $tenant->id,
            'store_id' => $store->id,
            'family' => 'money',
            'component' => 'capture',
            'fingerprint' => hash('sha256', fake()->uuid()),
            'state' => Incident::STATE_OPEN,
            'severity' => Incident::SEVERITY_WARNING,
            'title_code' => 'MONEY_CAPTURE_MISSING',
            'currency' => 'EUR',
            'verified_discrepancy_minor' => 18400,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'first_bad_at' => $now,
            'revision' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
