<?php

namespace Tests\Feature\Incidents;

use App\Models\Incident;
use App\Models\Integration;
use App\Models\Membership;
use App\Models\Order;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class IncidentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_triggering_reconciliation_creates_an_incident_visible_via_the_list(): void
    {
        $context = $this->context('admin');
        $order = $this->order($context, ['paid_marked_at' => now()->subMinutes(31)]);

        $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/v1/stores/{$context['store']->id}/reconciliations", [
                'order_ids' => [$order->id],
            ])
            ->assertStatus(202);

        $response = $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->getJson('/api/v1/incidents');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.state', Incident::STATE_OPEN)
            ->assertJsonPath('data.0.family', 'money')
            ->assertJsonPath('data.0.component', 'capture');
    }

    public function test_incident_detail_includes_signals_and_activity(): void
    {
        $context = $this->context('owner');
        $incidentId = $this->triggerIncident($context);

        $response = $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->getJson("/api/v1/incidents/{$incidentId}");

        $response
            ->assertOk()
            ->assertJsonPath('id', $incidentId)
            ->assertJsonCount(1, 'signals')
            ->assertJsonCount(1, 'activity')
            ->assertJsonPath('activity.0.kind', 'created')
            ->assertJsonPath('currency', 'EUR')
            ->assertJsonPath('currency_exponent', 2)
            ->assertJsonPath('active_suppression', null)
            ->assertJsonCount(1, 'findings')
            ->assertJsonPath('findings.0.rule_code', 'MONEY_CAPTURE_MISSING')
            ->assertJsonPath('findings.0.order_display_number', '#1001')
            ->assertJsonPath('findings.0.expected_minor', '18400');
    }

    public function test_list_returns_newest_first_with_a_cursor_and_filters_several_states(): void
    {
        $context = $this->context('viewer');
        $ids = [];

        foreach (['resolved', 'open', 'acknowledged', 'open'] as $index => $state) {
            $ids[] = Incident::query()->create([
                'tenant_id' => $context['tenant']->id,
                'store_id' => $context['store']->id,
                'family' => 'integration',
                'component' => 'connector',
                'fingerprint' => "fp-{$index}",
                'state' => $state,
                'severity' => 'warning',
                'title_code' => 'INTEGRATION_STALE',
                'first_seen_at' => now(),
                'last_seen_at' => now(),
                'revision' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ])->id;
        }

        $request = $this->actingAs($context['user'])->withSession(['active_tenant_id' => $context['tenant']->id]);

        $first = $request->getJson('/api/v1/incidents?limit=2')
            ->assertOk()
            ->assertJsonPath('data.0.id', $ids[3])
            ->assertJsonPath('data.1.id', $ids[2]);

        $request->getJson('/api/v1/incidents?limit=2&cursor='.urlencode((string) $first->json('next_cursor')))
            ->assertOk()
            ->assertJsonPath('data.0.id', $ids[1])
            ->assertJsonPath('data.1.id', $ids[0])
            ->assertJsonPath('next_cursor', null);

        $active = $request->getJson('/api/v1/incidents?state=open,acknowledged')->assertOk();

        $this->assertSame([$ids[3], $ids[2], $ids[1]], array_column($active->json('data'), 'id'));

        $request->getJson('/api/v1/incidents?state=open,closed')
            ->assertUnprocessable();
    }

    public function test_operator_can_acknowledge_but_viewer_cannot(): void
    {
        $context = $this->context('operator');
        $incidentId = $this->triggerIncident($context);

        $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->withHeader('If-Match', '"1"')
            ->postJson("/api/v1/incidents/{$incidentId}/acknowledge")
            ->assertOk()
            ->assertJsonPath('state', Incident::STATE_ACKNOWLEDGED);

        $viewerContext = $this->context('viewer', $context['tenant'], $context['store'], $context['integration']);

        $this->actingAs($viewerContext['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->withHeader('If-Match', '"2"')
            ->postJson("/api/v1/incidents/{$incidentId}/acknowledge")
            ->assertForbidden();
    }

    public function test_operator_can_resolve_with_a_reason(): void
    {
        $context = $this->context('operator');
        $incidentId = $this->triggerIncident($context);

        $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->postJson("/api/v1/incidents/{$incidentId}/resolve", [
                'reason' => 'Matched the capture manually.',
            ])
            ->assertStatus(428);

        $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->withHeader('If-Match', '"5"')
            ->postJson("/api/v1/incidents/{$incidentId}/resolve", [
                'reason' => 'Matched the capture manually.',
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'version_conflict');

        $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->withHeader('If-Match', '"1"')
            ->postJson("/api/v1/incidents/{$incidentId}/resolve", [
                'reason' => 'Matched the capture manually.',
            ])
            ->assertOk()
            ->assertHeader('ETag', '"2"')
            ->assertJsonPath('state', Incident::STATE_RESOLVED)
            ->assertJsonPath('resolution_reason', 'Matched the capture manually.');

        $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->withHeader('If-Match', '"2"')
            ->postJson("/api/v1/incidents/{$incidentId}/resolve", [
                'reason' => 'Trying again.',
            ])
            ->assertStatus(409);
    }

    public function test_operator_can_comment(): void
    {
        $context = $this->context('operator');
        $incidentId = $this->triggerIncident($context);

        $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->postJson("/api/v1/incidents/{$incidentId}/comments", [
                'text' => 'Looking into this now.',
            ])
            ->assertCreated()
            ->assertJsonPath('kind', 'comment')
            ->assertJsonPath('data.text', 'Looking into this now.');
    }

    public function test_admin_can_snooze_and_revoke_a_suppression_but_operator_cannot_snooze(): void
    {
        $context = $this->context('admin');
        $incidentId = $this->triggerIncident($context);

        $response = $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->postJson("/api/v1/incidents/{$incidentId}/snooze", [
                'until' => now()->addDays(3)->toJSON(),
                'reason' => 'Known issue, already being handled.',
            ]);
        $response->assertCreated();
        $suppressionId = $response->json('id');

        $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->getJson("/api/v1/incidents/{$incidentId}")
            ->assertOk()
            ->assertJsonPath('active_suppression.id', $suppressionId)
            ->assertJsonPath('state', Incident::STATE_OPEN);

        $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->postJson("/api/v1/suppressions/{$suppressionId}/revoke")
            ->assertOk()
            ->assertJsonPath('id', $suppressionId)
            ->assertJsonPath('revoked_at', fn ($value) => $value !== null);

        $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->getJson("/api/v1/incidents/{$incidentId}")
            ->assertJsonPath('active_suppression', null);

        $operatorContext = $this->context('operator', $context['tenant'], $context['store'], $context['integration']);

        $this->actingAs($operatorContext['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->postJson("/api/v1/incidents/{$incidentId}/snooze", [
                'until' => now()->addDays(3)->toJSON(),
                'reason' => 'Operator should not be able to do this.',
            ])
            ->assertForbidden();
    }

    public function test_user_cannot_read_or_act_on_a_foreign_tenant_incident(): void
    {
        $context = $this->context('admin');
        $incidentId = $this->triggerIncident($context);
        $foreignContext = $this->context('admin');

        $this->actingAs($foreignContext['user'])
            ->withSession(['active_tenant_id' => $foreignContext['tenant']->id])
            ->getJson("/api/v1/incidents/{$incidentId}")
            ->assertNotFound();

        $this->actingAs($foreignContext['user'])
            ->withSession(['active_tenant_id' => $foreignContext['tenant']->id])
            ->withHeader('If-Match', '"1"')
            ->postJson("/api/v1/incidents/{$incidentId}/acknowledge")
            ->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function triggerIncident(array $context): string
    {
        $order = $this->order($context, ['paid_marked_at' => now()->subMinutes(31)]);

        $response = $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/v1/stores/{$context['store']->id}/reconciliations", [
                'order_ids' => [$order->id],
            ]);

        /** @var Incident $incident */
        $incident = Incident::query()->firstOrFail();

        return $incident->id;
    }

    /**
     * @return array{user: User, tenant: Tenant, store: Store, integration: Integration}
     */
    private function context(string $role, ?Tenant $tenant = null, ?Store $store = null, ?Integration $integration = null): array
    {
        $user = User::query()->create([
            'name' => 'Incident Tester',
            'email' => fake()->unique()->safeEmail(),
            'password_hash' => Hash::make('very-secure-password'),
            'locale' => 'ru',
        ]);
        $tenant ??= Tenant::query()->create([
            'name' => fake()->company(),
            'timezone' => 'Europe/Kyiv',
        ]);
        Membership::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role' => $role,
        ]);
        $store ??= Store::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Main Shop',
            'base_url' => 'https://'.fake()->domainName(),
            'timezone' => 'Europe/Kyiv',
            'default_currency' => 'EUR',
        ]);
        $integration ??= Integration::query()->create([
            'tenant_id' => $tenant->id,
            'store_id' => $store->id,
            'provider' => 'stripe',
            'install_id' => fake()->uuid(),
            'mode' => 'live',
            'source_authority' => Integration::SOURCE_INDEPENDENT_PROVIDER,
            'status' => Integration::STATUS_ACTIVE,
            'capabilities' => [],
            'connector_version' => '1.0.0',
            'health' => [],
        ]);

        return compact('user', 'tenant', 'store', 'integration');
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $overrides
     */
    private function order(array $context, array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'tenant_id' => $context['tenant']->id,
            'store_id' => $context['store']->id,
            'integration_id' => $context['integration']->id,
            'external_id' => fake()->uuid(),
            'display_number' => '#1001',
            'source_revision' => 1,
            'status' => 'processing',
            'mode' => 'live',
            'currency' => 'EUR',
            'currency_exponent' => 2,
            'total_minor' => 18400,
            'payment_expected' => true,
            'financial_support' => 'supported',
            'is_synthetic' => false,
            'source_created_at' => now()->subHours(3),
            'source_updated_at' => now()->subHours(2),
            'current_payload_hash' => hash('sha256', fake()->uuid()),
            'metadata' => [],
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }
}
