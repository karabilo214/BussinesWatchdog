<?php

namespace Tests\Feature\Payments;

use App\Models\AuditLog;
use App\Models\FinancialTransaction;
use App\Models\Integration;
use App\Models\Membership;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentAllocationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_payment_allocation_with_audit_log(): void
    {
        $context = $this->context('admin');

        $response = $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/payment-allocations', [
                'order_id' => $context['order']->id,
                'payment_id' => $context['payment']->id,
                'capture_transaction_id' => $context['capture']->id,
                'amount_minor' => '18400',
                'currency' => 'EUR',
                'reason' => 'Matched by exact charge reference.',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('order_id', $context['order']->id)
            ->assertJsonPath('amount_minor', '18400')
            ->assertJsonPath('strategy', PaymentAllocation::STRATEGY_MANUAL);

        $this->assertDatabaseHas('audit_log', [
            'entity_type' => AuditLog::ENTITY_PAYMENT_ALLOCATION,
            'action' => AuditLog::ACTION_PAYMENT_ALLOCATION_CREATED,
        ]);
    }

    public function test_operator_cannot_create_payment_allocation(): void
    {
        $context = $this->context('operator');

        $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/payment-allocations', [
                'order_id' => $context['order']->id,
                'payment_id' => $context['payment']->id,
                'capture_transaction_id' => $context['capture']->id,
                'amount_minor' => '18400',
                'currency' => 'EUR',
                'reason' => 'Matched by exact charge reference.',
            ])
            ->assertForbidden();
    }

    public function test_create_requires_idempotency_key(): void
    {
        $context = $this->context('admin');

        $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->postJson('/api/v1/payment-allocations', [
                'order_id' => $context['order']->id,
                'payment_id' => $context['payment']->id,
                'capture_transaction_id' => $context['capture']->id,
                'amount_minor' => '18400',
                'currency' => 'EUR',
                'reason' => 'Matched by exact charge reference.',
            ])
            ->assertStatus(400);
    }

    public function test_repeating_the_same_idempotency_key_and_body_replays_the_same_result(): void
    {
        $context = $this->context('admin');
        $payload = [
            'order_id' => $context['order']->id,
            'payment_id' => $context['payment']->id,
            'capture_transaction_id' => $context['capture']->id,
            'amount_minor' => '18400',
            'currency' => 'EUR',
            'reason' => 'Matched by exact charge reference.',
        ];
        $idempotencyKey = (string) Str::uuid();

        $first = $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->postJson('/api/v1/payment-allocations', $payload);
        $second = $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->postJson('/api/v1/payment-allocations', $payload);

        $first->assertCreated();
        $second->assertCreated();
        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertSame(1, PaymentAllocation::query()->count());
    }

    public function test_repeating_the_same_idempotency_key_with_a_different_body_conflicts(): void
    {
        $context = $this->context('admin');
        $idempotencyKey = (string) Str::uuid();

        $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->postJson('/api/v1/payment-allocations', [
                'order_id' => $context['order']->id,
                'payment_id' => $context['payment']->id,
                'capture_transaction_id' => $context['capture']->id,
                'amount_minor' => '18400',
                'currency' => 'EUR',
                'reason' => 'Matched by exact charge reference.',
            ])
            ->assertCreated();

        $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->postJson('/api/v1/payment-allocations', [
                'order_id' => $context['order']->id,
                'payment_id' => $context['payment']->id,
                'capture_transaction_id' => $context['capture']->id,
                'amount_minor' => '100',
                'currency' => 'EUR',
                'reason' => 'Different request body entirely.',
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'idempotency_key_conflict');
    }

    public function test_create_rejects_currency_that_does_not_match_the_capture_transaction(): void
    {
        $context = $this->context('admin');

        $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/payment-allocations', [
                'order_id' => $context['order']->id,
                'payment_id' => $context['payment']->id,
                'capture_transaction_id' => $context['capture']->id,
                'amount_minor' => '18400',
                'currency' => 'USD',
                'reason' => 'Matched by exact charge reference.',
            ])
            ->assertStatus(422);
    }

    public function test_create_rejects_amount_exceeding_the_capture(): void
    {
        $context = $this->context('admin');

        $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/payment-allocations', [
                'order_id' => $context['order']->id,
                'payment_id' => $context['payment']->id,
                'capture_transaction_id' => $context['capture']->id,
                'amount_minor' => '99999',
                'currency' => 'EUR',
                'reason' => 'Matched by exact charge reference.',
            ])
            ->assertStatus(409);
    }

    public function test_create_rejects_unknown_order_in_tenant_as_not_found(): void
    {
        $context = $this->context('admin');

        $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/payment-allocations', [
                'order_id' => (string) Str::uuid(),
                'payment_id' => $context['payment']->id,
                'capture_transaction_id' => $context['capture']->id,
                'amount_minor' => '18400',
                'currency' => 'EUR',
                'reason' => 'Matched by exact charge reference.',
            ])
            ->assertNotFound();
    }

    public function test_admin_can_revoke_allocation_with_reason_and_audit_log(): void
    {
        $context = $this->context('admin');
        $allocationId = $this->createAllocation($context);

        $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->postJson("/api/v1/payment-allocations/{$allocationId}/revoke", [
                'reason' => 'Wrong order matched manually.',
            ])
            ->assertNoContent();

        $this->assertNotNull(PaymentAllocation::query()->find($allocationId)->revoked_at);
        $this->assertDatabaseHas('audit_log', [
            'entity_type' => AuditLog::ENTITY_PAYMENT_ALLOCATION,
            'entity_id' => $allocationId,
            'action' => AuditLog::ACTION_PAYMENT_ALLOCATION_REVOKED,
        ]);
    }

    public function test_revoke_requires_a_reason(): void
    {
        $context = $this->context('admin');
        $allocationId = $this->createAllocation($context);

        $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->postJson("/api/v1/payment-allocations/{$allocationId}/revoke", [])
            ->assertStatus(422);
    }

    public function test_user_cannot_revoke_a_foreign_tenant_allocation(): void
    {
        $context = $this->context('admin');
        $allocationId = $this->createAllocation($context);
        $foreignContext = $this->context('admin');

        $this->actingAs($foreignContext['user'])
            ->withSession(['active_tenant_id' => $foreignContext['tenant']->id])
            ->postJson("/api/v1/payment-allocations/{$allocationId}/revoke", [
                'reason' => 'Trying to reach another tenant allocation.',
            ])
            ->assertNotFound();
    }

    /**
     * @return array{user: User, tenant: Tenant, store: Store, integration: Integration, order: Order, payment: Payment, capture: FinancialTransaction}
     */
    private function context(string $role): array
    {
        $user = User::query()->create([
            'name' => 'Allocation Tester',
            'email' => fake()->unique()->safeEmail(),
            'password_hash' => Hash::make('very-secure-password'),
            'locale' => 'ru',
        ]);
        $tenant = Tenant::query()->create([
            'name' => fake()->company(),
            'timezone' => 'Europe/Kyiv',
        ]);
        Membership::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role' => $role,
        ]);
        $store = Store::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Main Shop',
            'base_url' => 'https://'.fake()->domainName(),
            'timezone' => 'Europe/Kyiv',
            'default_currency' => 'EUR',
        ]);
        $integration = Integration::query()->create([
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
        $capture = FinancialTransaction::query()->create([
            'tenant_id' => $tenant->id,
            'store_id' => $store->id,
            'integration_id' => $integration->id,
            'payment_id' => $payment->id,
            'external_operation_id' => 'cap_'.fake()->uuid(),
            'kind' => 'capture',
            'status' => 'succeeded',
            'currency' => 'EUR',
            'currency_exponent' => 2,
            'amount_minor' => 18400,
            'occurred_at' => now()->subMinute(),
            'source_authority' => Integration::SOURCE_INDEPENDENT_PROVIDER,
            'operation_hash' => hash('sha256', fake()->uuid()),
            'metadata' => [],
            'created_at' => now(),
        ]);

        return compact('user', 'tenant', 'store', 'integration', 'order', 'payment', 'capture');
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function createAllocation(array $context): string
    {
        $response = $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/payment-allocations', [
                'order_id' => $context['order']->id,
                'payment_id' => $context['payment']->id,
                'capture_transaction_id' => $context['capture']->id,
                'amount_minor' => '18400',
                'currency' => 'EUR',
                'reason' => 'Matched by exact charge reference.',
            ]);

        return $response->json('id');
    }
}
