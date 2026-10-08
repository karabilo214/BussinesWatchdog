<?php

namespace Tests\Feature\Payments;

use App\Exceptions\Payments\AllocationRejected;
use App\Models\AuditLog;
use App\Models\FinancialTransaction;
use App\Models\Integration;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Refund;
use App\Models\Store;
use App\Models\Tenant;
use App\Support\Payments\PaymentAllocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentAllocationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_allocates_succeeded_capture_to_order(): void
    {
        $context = $this->context();

        $allocation = app(PaymentAllocationService::class)->allocateCapture(
            $context['payment'],
            $context['capture'],
            $context['order'],
            18400,
            PaymentAllocation::STRATEGY_EXACT_REFERENCE,
            ['reference' => 'transaction_ref'],
        );

        $this->assertSame($context['tenant']->id, $allocation->tenant_id);
        $this->assertSame($context['order']->id, $allocation->order_id);
        $this->assertSame($context['capture']->id, $allocation->capture_transaction_id);
        $this->assertSame(18400, $allocation->amount_minor);
        $this->assertSame('EUR', $allocation->currency);
    }

    public function test_it_rejects_capture_allocation_that_exceeds_transaction_amount(): void
    {
        $context = $this->context();
        $service = app(PaymentAllocationService::class);
        $service->allocateCapture(
            $context['payment'],
            $context['capture'],
            $context['order'],
            18000,
            PaymentAllocation::STRATEGY_EXACT_REFERENCE,
            [],
        );

        $this->expectException(AllocationRejected::class);
        $this->expectExceptionMessage(PaymentAllocationService::ERROR_AMOUNT_EXCEEDS_CAPTURE);

        $service->allocateCapture(
            $context['payment'],
            $context['capture'],
            $context['order'],
            401,
            PaymentAllocation::STRATEGY_MANUAL,
            [],
        );
    }

    public function test_it_rejects_cross_tenant_capture_allocation(): void
    {
        $context = $this->context();
        $foreign = $this->context();

        $this->expectException(AllocationRejected::class);
        $this->expectExceptionMessage(PaymentAllocationService::ERROR_SCOPE_MISMATCH);

        app(PaymentAllocationService::class)->allocateCapture(
            $context['payment'],
            $context['capture'],
            $foreign['order'],
            1000,
            PaymentAllocation::STRATEGY_MANUAL,
            [],
        );
    }

    public function test_it_allocates_succeeded_provider_refund_to_payment_allocation(): void
    {
        $context = $this->context();
        $service = app(PaymentAllocationService::class);
        $paymentAllocation = $service->allocateCapture(
            $context['payment'],
            $context['capture'],
            $context['order'],
            18400,
            PaymentAllocation::STRATEGY_EXACT_REFERENCE,
            [],
        );

        $refundAllocation = $service->allocateRefund(
            $context['refund'],
            $context['refundTransaction'],
            $paymentAllocation,
            5000,
            PaymentAllocation::STRATEGY_EXACT_REFERENCE,
            ['provider_ref' => 're_demo_1001'],
        );

        $this->assertSame($context['refund']->id, $refundAllocation->refund_id);
        $this->assertSame($context['refundTransaction']->id, $refundAllocation->refund_transaction_id);
        $this->assertSame($paymentAllocation->id, $refundAllocation->payment_allocation_id);
        $this->assertSame(5000, $refundAllocation->amount_minor);
        $this->assertSame('EUR', $refundAllocation->currency);
    }

    public function test_it_rejects_refund_allocation_that_exceeds_refund_transaction_amount(): void
    {
        $context = $this->context();
        $service = app(PaymentAllocationService::class);
        $paymentAllocation = $service->allocateCapture(
            $context['payment'],
            $context['capture'],
            $context['order'],
            18400,
            PaymentAllocation::STRATEGY_EXACT_REFERENCE,
            [],
        );
        $service->allocateRefund(
            $context['refund'],
            $context['refundTransaction'],
            $paymentAllocation,
            4900,
            PaymentAllocation::STRATEGY_EXACT_REFERENCE,
            [],
        );

        $this->expectException(AllocationRejected::class);
        $this->expectExceptionMessage(PaymentAllocationService::ERROR_AMOUNT_EXCEEDS_REFUND);

        $service->allocateRefund(
            $context['refund'],
            $context['refundTransaction'],
            $paymentAllocation,
            101,
            PaymentAllocation::STRATEGY_MANUAL,
            [],
        );
    }

    public function test_it_rejects_refund_allocation_when_refund_order_does_not_match_payment_allocation_order(): void
    {
        $context = $this->context();
        $service = app(PaymentAllocationService::class);
        $paymentAllocation = $service->allocateCapture(
            $context['payment'],
            $context['capture'],
            $context['order'],
            18400,
            PaymentAllocation::STRATEGY_EXACT_REFERENCE,
            [],
        );

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

        $this->expectException(AllocationRejected::class);
        $this->expectExceptionMessage(PaymentAllocationService::ERROR_ORDER_MISMATCH);

        $service->allocateRefund(
            $otherRefund,
            $context['refundTransaction'],
            $paymentAllocation,
            5000,
            PaymentAllocation::STRATEGY_EXACT_REFERENCE,
            [],
        );
    }

    public function test_it_revokes_capture_allocation_and_frees_capacity_for_a_new_allocation(): void
    {
        $context = $this->context();
        $service = app(PaymentAllocationService::class);
        $allocation = $service->allocateCapture(
            $context['payment'],
            $context['capture'],
            $context['order'],
            18400,
            PaymentAllocation::STRATEGY_EXACT_REFERENCE,
            [],
        );

        $revoked = $service->revokeCaptureAllocation($allocation, 'wrong order matched');

        $this->assertNotNull($revoked->revoked_at);
        $this->assertDatabaseHas('audit_log', [
            'entity_type' => AuditLog::ENTITY_PAYMENT_ALLOCATION,
            'entity_id' => $allocation->id,
            'action' => AuditLog::ACTION_PAYMENT_ALLOCATION_REVOKED,
        ]);

        $reallocated = $service->allocateCapture(
            $context['payment'],
            $context['capture'],
            $context['order'],
            18400,
            PaymentAllocation::STRATEGY_MANUAL,
            [],
        );

        $this->assertSame(18400, $reallocated->amount_minor);
    }

    public function test_it_rejects_revoking_capture_allocation_twice(): void
    {
        $context = $this->context();
        $service = app(PaymentAllocationService::class);
        $allocation = $service->allocateCapture(
            $context['payment'],
            $context['capture'],
            $context['order'],
            18400,
            PaymentAllocation::STRATEGY_EXACT_REFERENCE,
            [],
        );
        $service->revokeCaptureAllocation($allocation, 'duplicate link');

        $this->expectException(AllocationRejected::class);
        $this->expectExceptionMessage(PaymentAllocationService::ERROR_ALREADY_REVOKED);

        $service->revokeCaptureAllocation($allocation, 'duplicate link');
    }

    public function test_it_requires_a_reason_to_revoke_capture_allocation(): void
    {
        $context = $this->context();
        $service = app(PaymentAllocationService::class);
        $allocation = $service->allocateCapture(
            $context['payment'],
            $context['capture'],
            $context['order'],
            18400,
            PaymentAllocation::STRATEGY_EXACT_REFERENCE,
            [],
        );

        $this->expectException(AllocationRejected::class);
        $this->expectExceptionMessage(PaymentAllocationService::ERROR_REASON_REQUIRED);

        $service->revokeCaptureAllocation($allocation, '   ');
    }

    public function test_it_rejects_revoking_capture_allocation_with_active_refund_allocations(): void
    {
        $context = $this->context();
        $service = app(PaymentAllocationService::class);
        $paymentAllocation = $service->allocateCapture(
            $context['payment'],
            $context['capture'],
            $context['order'],
            18400,
            PaymentAllocation::STRATEGY_EXACT_REFERENCE,
            [],
        );
        $service->allocateRefund(
            $context['refund'],
            $context['refundTransaction'],
            $paymentAllocation,
            5000,
            PaymentAllocation::STRATEGY_EXACT_REFERENCE,
            [],
        );

        $this->expectException(AllocationRejected::class);
        $this->expectExceptionMessage(PaymentAllocationService::ERROR_HAS_ACTIVE_REFUND_ALLOCATIONS);

        $service->revokeCaptureAllocation($paymentAllocation, 'trying to unlink too early');
    }

    public function test_it_revokes_capture_allocation_after_its_refund_allocation_is_revoked(): void
    {
        $context = $this->context();
        $service = app(PaymentAllocationService::class);
        $paymentAllocation = $service->allocateCapture(
            $context['payment'],
            $context['capture'],
            $context['order'],
            18400,
            PaymentAllocation::STRATEGY_EXACT_REFERENCE,
            [],
        );
        $refundAllocation = $service->allocateRefund(
            $context['refund'],
            $context['refundTransaction'],
            $paymentAllocation,
            5000,
            PaymentAllocation::STRATEGY_EXACT_REFERENCE,
            [],
        );

        $service->revokeRefundAllocation($refundAllocation, 'wrong refund matched');
        $revoked = $service->revokeCaptureAllocation($paymentAllocation, 'order resolved manually');

        $this->assertNotNull($revoked->revoked_at);
    }

    public function test_it_rejects_revoking_refund_allocation_twice(): void
    {
        $context = $this->context();
        $service = app(PaymentAllocationService::class);
        $paymentAllocation = $service->allocateCapture(
            $context['payment'],
            $context['capture'],
            $context['order'],
            18400,
            PaymentAllocation::STRATEGY_EXACT_REFERENCE,
            [],
        );
        $refundAllocation = $service->allocateRefund(
            $context['refund'],
            $context['refundTransaction'],
            $paymentAllocation,
            5000,
            PaymentAllocation::STRATEGY_EXACT_REFERENCE,
            [],
        );
        $service->revokeRefundAllocation($refundAllocation, 'wrong refund matched');

        $this->expectException(AllocationRejected::class);
        $this->expectExceptionMessage(PaymentAllocationService::ERROR_ALREADY_REVOKED);

        $service->revokeRefundAllocation($refundAllocation, 'wrong refund matched');
    }

    public function test_it_revokes_refund_allocation_and_frees_capacity_for_a_new_allocation(): void
    {
        $context = $this->context();
        $service = app(PaymentAllocationService::class);
        $paymentAllocation = $service->allocateCapture(
            $context['payment'],
            $context['capture'],
            $context['order'],
            18400,
            PaymentAllocation::STRATEGY_EXACT_REFERENCE,
            [],
        );
        $refundAllocation = $service->allocateRefund(
            $context['refund'],
            $context['refundTransaction'],
            $paymentAllocation,
            5000,
            PaymentAllocation::STRATEGY_EXACT_REFERENCE,
            [],
        );

        $service->revokeRefundAllocation($refundAllocation, 'wrong refund matched');

        $this->assertDatabaseHas('audit_log', [
            'entity_type' => AuditLog::ENTITY_REFUND_ALLOCATION,
            'entity_id' => $refundAllocation->id,
            'action' => AuditLog::ACTION_REFUND_ALLOCATION_REVOKED,
        ]);

        $reallocated = $service->allocateRefund(
            $context['refund'],
            $context['refundTransaction'],
            $paymentAllocation,
            5000,
            PaymentAllocation::STRATEGY_MANUAL,
            [],
        );

        $this->assertSame(5000, $reallocated->amount_minor);
    }

    /**
     * @return array{
     *     tenant: Tenant,
     *     store: Store,
     *     integration: Integration,
     *     order: Order,
     *     payment: Payment,
     *     capture: FinancialTransaction,
     *     refund: Refund,
     *     refundTransaction: FinancialTransaction
     * }
     */
    private function context(): array
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
            'intent_ref' => 'pi_demo_1001',
            'charge_ref' => 'ch_demo_1001',
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

        return compact('tenant', 'store', 'integration', 'order', 'payment', 'capture', 'refund', 'refundTransaction');
    }
}
