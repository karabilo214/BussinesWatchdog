<?php

namespace Tests\Feature\Payments;

use App\Models\AuditLog;
use App\Models\FinancialTransaction;
use App\Models\Integration;
use App\Models\Membership;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Refund;
use App\Models\RefundAllocation;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Payments\PaymentAllocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class RefundAllocationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_refund_allocation_with_audit_log(): void
    {
        $context = $this->context('admin');

        $response = $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/refund-allocations', [
                'refund_id' => $context['refund']->id,
                'refund_transaction_id' => $context['refundTransaction']->id,
                'payment_allocation_id' => $context['paymentAllocation']->id,
                'amount_minor' => '5000',
                'currency' => 'EUR',
                'reason' => 'Matched by exact refund reference.',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('refund_id', $context['refund']->id)
            ->assertJsonPath('amount_minor', '5000');

        $this->assertDatabaseHas('audit_log', [
            'entity_type' => AuditLog::ENTITY_REFUND_ALLOCATION,
            'action' => AuditLog::ACTION_REFUND_ALLOCATION_CREATED,
        ]);
    }

    public function test_create_rejects_a_refund_for_a_different_order_than_the_payment_allocation(): void
    {
        $context = $this->context('admin');
        $otherOrder = Order::query()->create([
            'tenant_id' => $context['tenant']->id,
            'store_id' => $context['store']->id,
            'integration_id' => $context['integration']->id,
            'external_id' => fake()->uuid(),
            'display_number' => '#1002',
            'source_revision' => 1,
            'status' => 'processing',
            'mode' => 'live',
            'currency' => 'EUR',
            'currency_exponent' => 2,
            'total_minor' => 5000,
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
        $otherRefund = Refund::query()->create([
            'tenant_id' => $context['tenant']->id,
            'store_id' => $context['store']->id,
            'integration_id' => $context['integration']->id,
            'order_id' => $otherOrder->id,
            'external_id' => fake()->uuid(),
            'source_revision' => 1,
            'currency' => 'EUR',
            'currency_exponent' => 2,
            'amount_minor' => 5000,
            'external_required' => true,
            'provider_ref' => 're_other_1',
            'status' => 'recorded',
            'occurred_at' => now()->subMinute(),
            'current_payload_hash' => hash('sha256', fake()->uuid()),
            'updated_at' => now(),
        ]);

        $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/refund-allocations', [
                'refund_id' => $otherRefund->id,
                'refund_transaction_id' => $context['refundTransaction']->id,
                'payment_allocation_id' => $context['paymentAllocation']->id,
                'amount_minor' => '5000',
                'currency' => 'EUR',
                'reason' => 'Matched by exact refund reference.',
            ])
            ->assertStatus(422);
    }

    public function test_admin_can_revoke_refund_allocation_with_reason_and_audit_log(): void
    {
        $context = $this->context('admin');
        $allocation = $this->createRefundAllocation($context);

        $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->postJson("/api/v1/refund-allocations/{$allocation->id}/revoke", [
                'reason' => 'Wrong refund matched manually.',
            ])
            ->assertNoContent();

        $this->assertNotNull(RefundAllocation::query()->find($allocation->id)->revoked_at);
        $this->assertDatabaseHas('audit_log', [
            'entity_type' => AuditLog::ENTITY_REFUND_ALLOCATION,
            'entity_id' => $allocation->id,
            'action' => AuditLog::ACTION_REFUND_ALLOCATION_REVOKED,
        ]);
    }

    public function test_user_cannot_revoke_a_foreign_tenant_refund_allocation(): void
    {
        $context = $this->context('admin');
        $allocation = $this->createRefundAllocation($context);
        $foreignContext = $this->context('admin');

        $this->actingAs($foreignContext['user'])
            ->withSession(['active_tenant_id' => $foreignContext['tenant']->id])
            ->postJson("/api/v1/refund-allocations/{$allocation->id}/revoke", [
                'reason' => 'Trying to reach another tenant allocation.',
            ])
            ->assertNotFound();
    }

    /**
     * @return array<string, mixed>
     */
    private function context(string $role): array
    {
        $user = User::query()->create([
            'name' => 'Refund Allocation Tester',
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
        $paymentAllocation = app(PaymentAllocationService::class)->allocateCapture(
            $payment,
            $capture,
            $order,
            18400,
            PaymentAllocation::STRATEGY_EXACT_REFERENCE,
            [],
        );
        $refund = Refund::query()->create([
            'tenant_id' => $tenant->id,
            'store_id' => $store->id,
            'integration_id' => $integration->id,
            'order_id' => $order->id,
            'external_id' => fake()->uuid(),
            'source_revision' => 1,
            'currency' => 'EUR',
            'currency_exponent' => 2,
            'amount_minor' => 5000,
            'external_required' => true,
            'provider_ref' => 're_demo_1001',
            'status' => 'recorded',
            'occurred_at' => now()->subMinute(),
            'current_payload_hash' => hash('sha256', fake()->uuid()),
            'updated_at' => now(),
        ]);
        $refundTransaction = FinancialTransaction::query()->create([
            'tenant_id' => $tenant->id,
            'store_id' => $store->id,
            'integration_id' => $integration->id,
            'payment_id' => $payment->id,
            'external_operation_id' => 'ref_'.fake()->uuid(),
            'kind' => 'refund',
            'status' => 'succeeded',
            'currency' => 'EUR',
            'currency_exponent' => 2,
            'amount_minor' => 5000,
            'occurred_at' => now()->subMinute(),
            'source_authority' => Integration::SOURCE_INDEPENDENT_PROVIDER,
            'operation_hash' => hash('sha256', fake()->uuid()),
            'metadata' => [],
            'created_at' => now(),
        ]);

        return compact('user', 'tenant', 'store', 'integration', 'order', 'payment', 'capture', 'paymentAllocation', 'refund', 'refundTransaction');
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function createRefundAllocation(array $context): RefundAllocation
    {
        $response = $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/refund-allocations', [
                'refund_id' => $context['refund']->id,
                'refund_transaction_id' => $context['refundTransaction']->id,
                'payment_allocation_id' => $context['paymentAllocation']->id,
                'amount_minor' => '5000',
                'currency' => 'EUR',
                'reason' => 'Matched by exact refund reference.',
            ]);

        return RefundAllocation::query()->findOrFail($response->json('id'));
    }
}
