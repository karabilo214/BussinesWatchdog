<?php

namespace Tests\Feature\Orders;

use App\Models\FinancialTransaction;
use App\Models\Integration;
use App\Models\Membership;
use App\Models\Order;
use App\Models\OrderRevision;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\ReconciliationFinding;
use App\Models\Refund;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Payments\PaymentAllocationService;
use App\Support\Reconciliation\OrderReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OrderApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_can_read_order_detail_with_findings_allocations_and_revisions(): void
    {
        $context = $this->context('viewer');
        $allocation = app(PaymentAllocationService::class)->allocateCapture(
            $context['payment'],
            $context['capture'],
            $context['order'],
            18400,
            PaymentAllocation::STRATEGY_EXACT_REFERENCE,
            [],
        );
        OrderRevision::query()->create([
            'tenant_id' => $context['tenant']->id,
            'store_id' => $context['store']->id,
            'order_id' => $context['order']->id,
            'event_id' => null,
            'source_revision' => 1,
            'snapshot' => ['data' => ['status' => 'processing', 'total_minor' => '18400']],
            'payload_hash' => hash('sha256', fake()->uuid()),
            'observed_at' => now()->subHours(1),
            'created_at' => now()->subHours(1),
        ]);
        app(OrderReconciliationService::class)->evaluate($context['order']->fresh());

        $response = $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->getJson("/api/v1/orders/{$context['order']->id}");

        $response
            ->assertOk()
            ->assertJsonPath('id', $context['order']->id)
            ->assertJsonPath('total_minor', '18400')
            ->assertJsonCount(1, 'captures')
            ->assertJsonPath('captures.0.id', $context['capture']->id)
            ->assertJsonCount(1, 'allocations')
            ->assertJsonPath('allocations.0.id', $allocation->id)
            ->assertJsonCount(1, 'revisions')
            ->assertJsonPath('revisions.0.source_revision', 1)
            ->assertJsonCount(1, 'findings')
            ->assertJsonPath('findings.0.rule_code', ReconciliationFinding::RULE_CAPTURE_AMOUNT)
            ->assertJsonPath('findings.0.status', ReconciliationFinding::STATUS_OK);
    }

    public function test_order_detail_includes_refunds_and_refund_allocations(): void
    {
        $context = $this->context('owner');
        $paymentAllocation = app(PaymentAllocationService::class)->allocateCapture(
            $context['payment'],
            $context['capture'],
            $context['order'],
            18400,
            PaymentAllocation::STRATEGY_EXACT_REFERENCE,
            [],
        );
        $refund = Refund::query()->create([
            'tenant_id' => $context['tenant']->id,
            'store_id' => $context['store']->id,
            'integration_id' => $context['integration']->id,
            'order_id' => $context['order']->id,
            'external_id' => fake()->uuid(),
            'source_revision' => 1,
            'currency' => 'EUR',
            'currency_exponent' => 2,
            'amount_minor' => 5000,
            'external_required' => true,
            'provider_ref' => 're_demo_1',
            'status' => 'recorded',
            'occurred_at' => now()->subMinute(),
            'current_payload_hash' => hash('sha256', fake()->uuid()),
            'updated_at' => now(),
        ]);
        $refundTransaction = FinancialTransaction::query()->create([
            'tenant_id' => $context['tenant']->id,
            'store_id' => $context['store']->id,
            'integration_id' => $context['integration']->id,
            'payment_id' => $context['payment']->id,
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
        app(PaymentAllocationService::class)->allocateRefund(
            $refund,
            $refundTransaction,
            $paymentAllocation,
            5000,
            PaymentAllocation::STRATEGY_EXACT_REFERENCE,
            [],
        );

        $response = $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->getJson("/api/v1/orders/{$context['order']->id}");

        $response
            ->assertOk()
            ->assertJsonCount(1, 'refunds')
            ->assertJsonPath('refunds.0.id', $refund->id)
            ->assertJsonCount(1, 'refund_transactions')
            ->assertJsonPath('refund_transactions.0.id', $refundTransaction->id)
            ->assertJsonCount(1, 'refund_allocations');
    }

    public function test_user_cannot_read_foreign_tenant_order(): void
    {
        $context = $this->context('owner');
        $foreignContext = $this->context('owner');

        $this->actingAs($foreignContext['user'])
            ->withSession(['active_tenant_id' => $foreignContext['tenant']->id])
            ->getJson("/api/v1/orders/{$context['order']->id}")
            ->assertNotFound();
    }

    /**
     * @return array{user: User, tenant: Tenant, store: Store, integration: Integration, order: Order, payment: Payment, capture: FinancialTransaction}
     */
    private function context(string $role): array
    {
        $user = User::query()->create([
            'name' => 'Order Tester',
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
}
