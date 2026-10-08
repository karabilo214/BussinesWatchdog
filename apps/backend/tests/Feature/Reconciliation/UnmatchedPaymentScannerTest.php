<?php

namespace Tests\Feature\Reconciliation;

use App\Models\FinancialTransaction;
use App\Models\Integration;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\ReconciliationFinding;
use App\Models\Store;
use App\Models\Tenant;
use App\Support\Payments\PaymentAllocationService;
use App\Support\Reconciliation\UnmatchedPaymentScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnmatchedPaymentScannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_an_old_unallocated_capture_as_payment_without_order(): void
    {
        $context = $this->context();
        $capture = $this->capture($context, 18400, now()->subHours(25));

        $run = app(UnmatchedPaymentScanner::class)->scan($context['store']);

        $findings = $run->findings()->get();
        $this->assertCount(1, $findings);
        $finding = $findings->first();
        $this->assertSame(ReconciliationFinding::RULE_PAYMENT_WITHOUT_ORDER, $finding->rule_code);
        $this->assertSame(ReconciliationFinding::STATUS_PENDING, $finding->status);
        $this->assertNull($finding->order_id);
        $this->assertSame($context['payment']->id, $finding->payment_id);
        $this->assertSame(18400, $finding->actual_minor);
    }

    public function test_it_does_not_report_a_capture_within_the_orphan_grace_window(): void
    {
        $context = $this->context();
        $this->capture($context, 18400, now()->subHours(1));

        $run = app(UnmatchedPaymentScanner::class)->scan($context['store']);

        $this->assertSame(0, $run->findings()->count());
    }

    public function test_it_does_not_report_a_capture_that_is_already_allocated(): void
    {
        $context = $this->context();
        $capture = $this->capture($context, 18400, now()->subHours(25));
        $order = Order::query()->create([
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
            'source_created_at' => now()->subHours(26),
            'source_updated_at' => now()->subHours(26),
            'current_payload_hash' => hash('sha256', fake()->uuid()),
            'metadata' => [],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        app(PaymentAllocationService::class)->allocateCapture(
            $context['payment'],
            $capture,
            $order,
            18400,
            PaymentAllocation::STRATEGY_EXACT_REFERENCE,
            [],
        );

        $run = app(UnmatchedPaymentScanner::class)->scan($context['store']);

        $this->assertSame(0, $run->findings()->count());
    }

    /**
     * @return array{tenant: Tenant, store: Store, integration: Integration, payment: Payment}
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
            'source_updated_at' => now()->subHours(25),
            'current_payload_hash' => hash('sha256', fake()->uuid()),
            'metadata' => [],
            'created_at' => now()->subHours(25),
            'updated_at' => now()->subHours(25),
        ]);

        return compact('tenant', 'store', 'integration', 'payment');
    }

    /**
     * @param  array{tenant: Tenant, store: Store, integration: Integration, payment: Payment}  $context
     */
    private function capture(array $context, int $amountMinor, \DateTimeInterface $occurredAt): FinancialTransaction
    {
        return FinancialTransaction::query()->create([
            'tenant_id' => $context['tenant']->id,
            'store_id' => $context['store']->id,
            'integration_id' => $context['integration']->id,
            'payment_id' => $context['payment']->id,
            'external_operation_id' => 'cap_'.fake()->uuid(),
            'kind' => 'capture',
            'status' => 'succeeded',
            'currency' => 'EUR',
            'currency_exponent' => 2,
            'amount_minor' => $amountMinor,
            'occurred_at' => $occurredAt,
            'source_authority' => Integration::SOURCE_INDEPENDENT_PROVIDER,
            'operation_hash' => hash('sha256', fake()->uuid()),
            'metadata' => [],
            'created_at' => now(),
        ]);
    }
}
