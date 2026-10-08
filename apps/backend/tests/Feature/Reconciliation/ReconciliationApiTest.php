<?php

namespace Tests\Feature\Reconciliation;

use App\Models\FinancialTransaction;
use App\Models\Integration;
use App\Models\Membership;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ReconciliationFinding;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReconciliationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_can_trigger_reconciliation_for_specific_orders(): void
    {
        $context = $this->context('operator');

        $response = $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/v1/stores/{$context['store']->id}/reconciliations", [
                'order_ids' => [$context['order']->id],
            ]);

        $response
            ->assertStatus(202)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.order_id', $context['order']->id)
            ->assertJsonPath('data.0.findings_count', 1);

        $this->assertDatabaseHas('reconciliation_findings', [
            'order_id' => $context['order']->id,
            'rule_code' => ReconciliationFinding::RULE_CAPTURE_MISSING,
        ]);
    }

    public function test_viewer_cannot_trigger_reconciliation(): void
    {
        $context = $this->context('viewer');

        $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/v1/stores/{$context['store']->id}/reconciliations", [
                'order_ids' => [$context['order']->id],
            ])
            ->assertForbidden();
    }

    public function test_trigger_without_order_ids_runs_the_store_wide_unmatched_payment_scan(): void
    {
        $context = $this->context('admin');
        FinancialTransaction::query()->create([
            'tenant_id' => $context['tenant']->id,
            'store_id' => $context['store']->id,
            'integration_id' => $context['integration']->id,
            'payment_id' => $context['payment']->id,
            'external_operation_id' => 'cap_orphan',
            'kind' => 'capture',
            'status' => 'succeeded',
            'currency' => 'EUR',
            'currency_exponent' => 2,
            'amount_minor' => 7700,
            'occurred_at' => now()->subHours(25),
            'source_authority' => Integration::SOURCE_INDEPENDENT_PROVIDER,
            'operation_hash' => hash('sha256', fake()->uuid()),
            'metadata' => [],
            'created_at' => now(),
        ]);

        $response = $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/v1/stores/{$context['store']->id}/reconciliations", []);

        $response
            ->assertStatus(202)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.order_id', null)
            ->assertJsonPath('data.0.findings_count', 1);

        $this->assertDatabaseHas('reconciliation_findings', [
            'rule_code' => ReconciliationFinding::RULE_PAYMENT_WITHOUT_ORDER,
        ]);
    }

    public function test_trigger_rejects_dry_run(): void
    {
        $context = $this->context('admin');

        $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/v1/stores/{$context['store']->id}/reconciliations", [
                'order_ids' => [$context['order']->id],
                'dry_run' => true,
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'dry_run_not_supported');
    }

    public function test_trigger_rejects_unknown_order_id(): void
    {
        $context = $this->context('admin');

        $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/v1/stores/{$context['store']->id}/reconciliations", [
                'order_ids' => [(string) Str::uuid()],
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'order_not_found');
    }

    public function test_viewer_can_list_findings_filtered_by_status(): void
    {
        $context = $this->context('viewer');
        $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/v1/stores/{$context['store']->id}/reconciliations", [
                'order_ids' => [$context['order']->id],
            ])
            ->assertStatus(403);

        // Trigger with a privileged actor so the viewer-read test only exercises the read path.
        $admin = $this->context('admin', $context['tenant'], $context['store'], $context['integration']);
        $this->actingAs($admin['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/v1/stores/{$context['store']->id}/reconciliations", [
                'order_ids' => [$context['order']->id],
            ])
            ->assertStatus(202);

        $response = $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->getJson("/api/v1/stores/{$context['store']->id}/findings?status=".ReconciliationFinding::STATUS_PENDING);

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.rule_code', ReconciliationFinding::RULE_CAPTURE_MISSING)
            ->assertJsonPath('data.0.status', ReconciliationFinding::STATUS_PENDING);
    }

    public function test_findings_pagination_returns_a_usable_cursor(): void
    {
        $context = $this->context('admin');

        foreach (range(1, 3) as $i) {
            $order = $this->extraOrder($context, "#200{$i}");
            $this->actingAs($context['user'])
                ->withSession(['active_tenant_id' => $context['tenant']->id])
                ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
                ->postJson("/api/v1/stores/{$context['store']->id}/reconciliations", [
                    'order_ids' => [$order->id],
                ])
                ->assertStatus(202);
        }

        $firstPage = $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->getJson("/api/v1/stores/{$context['store']->id}/findings?limit=2");

        $firstPage->assertOk()->assertJsonCount(2, 'data');
        $cursor = $firstPage->json('next_cursor');
        $this->assertNotNull($cursor);

        $secondPage = $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->getJson("/api/v1/stores/{$context['store']->id}/findings?limit=2&cursor=".urlencode($cursor));

        $secondPage->assertOk()->assertJsonCount(1, 'data');
        $this->assertNull($secondPage->json('next_cursor'));
    }

    public function test_user_cannot_list_findings_for_a_foreign_tenant_store(): void
    {
        $context = $this->context('admin');
        $foreignContext = $this->context('admin');

        $this->actingAs($foreignContext['user'])
            ->withSession(['active_tenant_id' => $foreignContext['tenant']->id])
            ->getJson("/api/v1/stores/{$context['store']->id}/findings")
            ->assertNotFound();
    }

    /**
     * @return array{user: User, tenant: Tenant, store: Store, integration: Integration, order: Order, payment: Payment}
     */
    private function context(string $role, ?Tenant $tenant = null, ?Store $store = null, ?Integration $integration = null): array
    {
        $user = User::query()->create([
            'name' => 'Reconciliation Tester',
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
        $order = Order::query()->create([
            'tenant_id' => $tenant->id,
            'store_id' => $store->id,
            'integration_id' => $integration->id,
            'external_id' => fake()->uuid(),
            'display_number' => '#1001',
            'source_revision' => 1,
            'status' => 'processing',
            'mode' => 'live',
            'currency' => 'EUR',
            'currency_exponent' => 2,
            'total_minor' => 18400,
            'payment_expected' => true,
            'paid_marked_at' => now()->subMinutes(10),
            'financial_support' => 'supported',
            'is_synthetic' => false,
            'source_created_at' => now()->subMinutes(2),
            'source_updated_at' => now()->subMinute(),
            'current_payload_hash' => hash('sha256', fake()->uuid()),
            'metadata' => [],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $payment = Payment::query()->create([
            'tenant_id' => $tenant->id,
            'store_id' => $store->id,
            'integration_id' => $integration->id,
            'external_id' => fake()->uuid(),
            'intent_ref' => 'pi_'.fake()->uuid(),
            'charge_ref' => 'ch_'.fake()->uuid(),
            'mode' => 'live',
            'currency' => 'EUR',
            'currency_exponent' => 2,
            'status' => 'captured',
            'source_authority' => Integration::SOURCE_INDEPENDENT_PROVIDER,
            'source_updated_at' => now()->subMinute(),
            'current_payload_hash' => hash('sha256', fake()->uuid()),
            'metadata' => [],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('user', 'tenant', 'store', 'integration', 'order', 'payment');
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function extraOrder(array $context, string $displayNumber): Order
    {
        return Order::query()->create([
            'tenant_id' => $context['tenant']->id,
            'store_id' => $context['store']->id,
            'integration_id' => $context['integration']->id,
            'external_id' => fake()->uuid(),
            'display_number' => $displayNumber,
            'source_revision' => 1,
            'status' => 'processing',
            'mode' => 'live',
            'currency' => 'EUR',
            'currency_exponent' => 2,
            'total_minor' => 5000,
            'payment_expected' => true,
            'paid_marked_at' => now()->subMinutes(10),
            'financial_support' => 'supported',
            'is_synthetic' => false,
            'source_created_at' => now()->subMinutes(2),
            'source_updated_at' => now()->subMinute(),
            'current_payload_hash' => hash('sha256', fake()->uuid()),
            'metadata' => [],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
