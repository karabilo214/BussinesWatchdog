<?php

namespace Tests\Feature\Reconciliation;

use App\Models\FinancialTransaction;
use App\Models\Integration;
use App\Models\Order;
use App\Models\OrderRevision;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\ReconciliationFinding;
use App\Models\ReconciliationRun;
use App\Models\Refund;
use App\Models\RefundAllocation;
use App\Models\Store;
use App\Models\Tenant;
use App\Support\Payments\PaymentAllocationService;
use App\Support\Reconciliation\OrderReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderReconciliationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_capture_missing_as_pending_within_grace(): void
    {
        $order = $this->order(['paid_marked_at' => now()->subMinutes(10)]);

        $run = app(OrderReconciliationService::class)->evaluate($order);

        $finding = $this->soleFinding($run);
        $this->assertSame(ReconciliationFinding::RULE_CAPTURE_MISSING, $finding->rule_code);
        $this->assertSame(ReconciliationFinding::STATUS_PENDING, $finding->status);
        $this->assertSame(-18400, $finding->difference_minor);
    }

    public function test_it_reports_capture_missing_as_mismatch_after_grace(): void
    {
        $order = $this->order(['paid_marked_at' => now()->subMinutes(31)]);

        $run = app(OrderReconciliationService::class)->evaluate($order);

        $finding = $this->soleFinding($run);
        $this->assertSame(ReconciliationFinding::RULE_CAPTURE_MISSING, $finding->rule_code);
        $this->assertSame(ReconciliationFinding::STATUS_MISMATCH, $finding->status);
    }

    public function test_it_reports_capture_amount_ok_when_capture_matches_order_total(): void
    {
        $order = $this->order(['paid_marked_at' => now()->subMinutes(10)]);
        $this->allocateCapture($order, 18400, 18400);

        $run = app(OrderReconciliationService::class)->evaluate($order);

        $finding = $this->soleFinding($run);
        $this->assertSame(ReconciliationFinding::RULE_CAPTURE_AMOUNT, $finding->rule_code);
        $this->assertSame(ReconciliationFinding::STATUS_OK, $finding->status);
        $this->assertSame(0, $finding->difference_minor);
    }

    public function test_it_reports_capture_amount_mismatch_when_capture_differs(): void
    {
        $order = $this->order(['paid_marked_at' => now()->subMinutes(10)]);
        $this->allocateCapture($order, 18400, 18000);

        $run = app(OrderReconciliationService::class)->evaluate($order);

        $finding = $this->soleFinding($run);
        $this->assertSame(ReconciliationFinding::RULE_CAPTURE_AMOUNT, $finding->rule_code);
        $this->assertSame(ReconciliationFinding::STATUS_MISMATCH, $finding->status);
        $this->assertSame(-400, $finding->difference_minor);
    }

    public function test_it_skips_capture_findings_when_order_not_marked_paid_and_no_capture(): void
    {
        $order = $this->order(['paid_marked_at' => null]);

        $run = app(OrderReconciliationService::class)->evaluate($order);

        $this->assertSame(0, $run->findings()->count());
        $this->assertSame(ReconciliationRun::STATUS_COMPLETED, $run->status);
    }

    public function test_it_reports_unsupported_finding_and_skips_other_rules(): void
    {
        $order = $this->order(['paid_marked_at' => now()->subMinutes(10), 'financial_support' => 'unsupported']);

        $run = app(OrderReconciliationService::class)->evaluate($order);

        $finding = $this->soleFinding($run);
        $this->assertSame(ReconciliationFinding::RULE_UNSUPPORTED, $finding->rule_code);
        $this->assertSame(ReconciliationFinding::STATUS_UNSUPPORTED, $finding->status);
    }

    public function test_it_reports_refund_missing_as_pending_then_mismatch_after_grace(): void
    {
        $order = $this->order(['paid_marked_at' => now()->subHours(2)]);
        $this->allocateCapture($order, 18400, 18400);
        $this->storeReportedRefund($order, 5000, true, now()->subMinutes(10));

        $run = app(OrderReconciliationService::class)->evaluate($order);

        $findings = $run->findings()->get()->keyBy('rule_code');
        $this->assertSame(ReconciliationFinding::STATUS_PENDING, $findings[ReconciliationFinding::RULE_REFUND_MISSING]->status);

        $orderPastGrace = $this->order(['paid_marked_at' => now()->subHours(2)]);
        $this->allocateCapture($orderPastGrace, 18400, 18400);
        $this->storeReportedRefund($orderPastGrace, 5000, true, now()->subMinutes(61));

        $runPastGrace = app(OrderReconciliationService::class)->evaluate($orderPastGrace);
        $findingsPastGrace = $runPastGrace->findings()->get()->keyBy('rule_code');
        $this->assertSame(ReconciliationFinding::STATUS_MISMATCH, $findingsPastGrace[ReconciliationFinding::RULE_REFUND_MISSING]->status);
    }

    public function test_it_reports_refund_extra_when_provider_refund_has_no_matching_store_reported_refund(): void
    {
        $order = $this->order(['paid_marked_at' => now()->subHours(2)]);
        $allocation = $this->allocateCapture($order, 18400, 18400);
        $refund = $this->bookkeepingOnlyRefund($order, 5000);
        $this->allocateRefund($order, $allocation, $refund, 5000, now()->subMinutes(10));

        $run = app(OrderReconciliationService::class)->evaluate($order);

        $findings = $run->findings()->get()->keyBy('rule_code');
        $finding = $findings[ReconciliationFinding::RULE_REFUND_EXTRA];
        $this->assertSame(ReconciliationFinding::STATUS_PENDING, $finding->status);
        $this->assertSame(0, $finding->refund_expected_minor);
        $this->assertSame(5000, $finding->refund_actual_minor);
    }

    public function test_it_reports_refund_reconciled_ok_when_store_reported_and_provider_refund_match(): void
    {
        $order = $this->order(['paid_marked_at' => now()->subHours(2)]);
        $allocation = $this->allocateCapture($order, 18400, 18400);
        $refund = $this->storeReportedRefund($order, 5000, true, now()->subMinutes(10));
        $this->allocateRefund($order, $allocation, $refund, 5000, now()->subMinutes(5));

        $run = app(OrderReconciliationService::class)->evaluate($order);

        $findings = $run->findings()->get()->keyBy('rule_code');
        $finding = $findings[ReconciliationFinding::RULE_REFUND_MISSING];
        $this->assertSame(ReconciliationFinding::STATUS_OK, $finding->status);
        $this->assertSame('refund_reconciled_ok', $finding->reason_code);
    }

    public function test_it_reports_multiple_captures_when_two_distinct_captures_are_allocated(): void
    {
        $order = $this->order(['paid_marked_at' => now()->subMinutes(10)]);
        $this->allocateCapture($order, 10000, 10000);
        $this->allocateCapture($order, 8400, 8400);

        $run = app(OrderReconciliationService::class)->evaluate($order);

        $findings = $run->findings()->get()->keyBy('rule_code');
        $this->assertSame(ReconciliationFinding::STATUS_MISMATCH, $findings[ReconciliationFinding::RULE_MULTIPLE_CAPTURES]->status);
        $this->assertSame(ReconciliationFinding::STATUS_OK, $findings[ReconciliationFinding::RULE_CAPTURE_AMOUNT]->status);
    }

    public function test_it_does_not_report_multiple_captures_for_a_single_capture(): void
    {
        $order = $this->order(['paid_marked_at' => now()->subMinutes(10)]);
        $this->allocateCapture($order, 18400, 18400);

        $run = app(OrderReconciliationService::class)->evaluate($order);

        $this->assertFalse($run->findings()->get()->keyBy('rule_code')->has(ReconciliationFinding::RULE_MULTIPLE_CAPTURES));
    }

    public function test_it_reports_currency_mismatch_when_the_referenced_payment_currency_differs(): void
    {
        $order = $this->order(['paid_marked_at' => now()->subMinutes(10), 'transaction_ref' => 'pi_mismatch_1']);
        Payment::query()->create([
            'tenant_id' => $order->tenant_id,
            'store_id' => $order->store_id,
            'integration_id' => $order->integration_id,
            'external_id' => fake()->uuid(),
            'intent_ref' => 'pi_mismatch_1',
            'charge_ref' => 'ch_mismatch_1',
            'mode' => 'live',
            'currency' => 'USD',
            'currency_exponent' => 2,
            'status' => 'captured',
            'source_authority' => Integration::SOURCE_INDEPENDENT_PROVIDER,
            'source_updated_at' => now()->subMinute(),
            'current_payload_hash' => hash('sha256', fake()->uuid()),
            'metadata' => [],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $run = app(OrderReconciliationService::class)->evaluate($order);

        $finding = $run->findings()->get()->keyBy('rule_code')[ReconciliationFinding::RULE_CURRENCY_MISMATCH];
        $this->assertSame(ReconciliationFinding::STATUS_MISMATCH, $finding->status);
    }

    public function test_it_does_not_report_currency_mismatch_without_a_transaction_ref(): void
    {
        $order = $this->order(['paid_marked_at' => now()->subMinutes(10), 'transaction_ref' => null]);

        $run = app(OrderReconciliationService::class)->evaluate($order);

        $this->assertFalse($run->findings()->get()->keyBy('rule_code')->has(ReconciliationFinding::RULE_CURRENCY_MISMATCH));
    }

    public function test_it_reports_order_changed_when_total_differs_from_the_revision_active_at_capture_time(): void
    {
        $order = $this->order(['paid_marked_at' => now()->subHours(2), 'total_minor' => 18400]);
        OrderRevision::query()->create([
            'tenant_id' => $order->tenant_id,
            'store_id' => $order->store_id,
            'order_id' => $order->id,
            'event_id' => null,
            'source_revision' => 1,
            'snapshot' => ['data' => ['total_minor' => '10000']],
            'payload_hash' => hash('sha256', fake()->uuid()),
            'observed_at' => now()->subHours(3),
            'created_at' => now()->subHours(3),
        ]);
        $this->allocateCapture($order, 10000, 10000);

        $run = app(OrderReconciliationService::class)->evaluate($order);

        $findings = $run->findings()->get()->keyBy('rule_code');
        $orderChanged = $findings[ReconciliationFinding::RULE_ORDER_CHANGED];
        $this->assertSame(ReconciliationFinding::STATUS_MISMATCH, $orderChanged->status);
        $this->assertSame(10000, $orderChanged->expected_minor);
        $this->assertSame(18400, $orderChanged->actual_minor);
        $this->assertSame(8400, $orderChanged->difference_minor);
    }

    public function test_it_does_not_report_order_changed_when_total_matches_the_revision_at_capture_time(): void
    {
        $order = $this->order(['paid_marked_at' => now()->subHours(2), 'total_minor' => 18400]);
        OrderRevision::query()->create([
            'tenant_id' => $order->tenant_id,
            'store_id' => $order->store_id,
            'order_id' => $order->id,
            'event_id' => null,
            'source_revision' => 1,
            'snapshot' => ['data' => ['total_minor' => '18400']],
            'payload_hash' => hash('sha256', fake()->uuid()),
            'observed_at' => now()->subHours(3),
            'created_at' => now()->subHours(3),
        ]);
        $this->allocateCapture($order, 18400, 18400);

        $run = app(OrderReconciliationService::class)->evaluate($order);

        $this->assertFalse($run->findings()->get()->keyBy('rule_code')->has(ReconciliationFinding::RULE_ORDER_CHANGED));
    }

    public function test_replaying_evaluation_creates_a_new_run_and_preserves_finding_history(): void
    {
        $order = $this->order(['paid_marked_at' => now()->subMinutes(10)]);

        $firstRun = app(OrderReconciliationService::class)->evaluate($order);
        $this->allocateCapture($order, 18400, 18400);
        $secondRun = app(OrderReconciliationService::class)->evaluate($order->fresh());

        $this->assertNotSame($firstRun->id, $secondRun->id);
        $this->assertSame(1, $firstRun->findings()->count());
        $this->assertSame(1, $secondRun->findings()->count());
        $this->assertSame(ReconciliationFinding::RULE_CAPTURE_MISSING, $firstRun->findings()->first()->rule_code);
        $this->assertSame(ReconciliationFinding::RULE_CAPTURE_AMOUNT, $secondRun->findings()->first()->rule_code);
    }

    private function soleFinding(ReconciliationRun $run): ReconciliationFinding
    {
        $findings = $run->findings()->get();
        $this->assertCount(1, $findings);

        return $findings->first();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function order(array $overrides = []): Order
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

        return Order::query()->create(array_merge([
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
            'source_created_at' => now()->subHours(3),
            'source_updated_at' => now()->subHours(2),
            'current_payload_hash' => hash('sha256', fake()->uuid()),
            'metadata' => [],
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function allocateCapture(Order $order, int $captureAmount, int $allocateAmount): PaymentAllocation
    {
        $payment = Payment::query()->create([
            'tenant_id' => $order->tenant_id,
            'store_id' => $order->store_id,
            'integration_id' => $order->integration_id,
            'external_id' => fake()->uuid(),
            'intent_ref' => 'pi_'.fake()->uuid(),
            'charge_ref' => 'ch_'.fake()->uuid(),
            'mode' => 'live',
            'currency' => $order->currency,
            'currency_exponent' => $order->currency_exponent,
            'status' => 'captured',
            'source_authority' => Integration::SOURCE_INDEPENDENT_PROVIDER,
            'source_updated_at' => now()->subMinute(),
            'current_payload_hash' => hash('sha256', fake()->uuid()),
            'metadata' => [],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $capture = FinancialTransaction::query()->create([
            'tenant_id' => $order->tenant_id,
            'store_id' => $order->store_id,
            'integration_id' => $order->integration_id,
            'payment_id' => $payment->id,
            'external_operation_id' => 'cap_'.fake()->uuid(),
            'kind' => 'capture',
            'status' => 'succeeded',
            'currency' => $order->currency,
            'currency_exponent' => $order->currency_exponent,
            'amount_minor' => $captureAmount,
            'occurred_at' => now()->subMinute(),
            'source_authority' => Integration::SOURCE_INDEPENDENT_PROVIDER,
            'operation_hash' => hash('sha256', fake()->uuid()),
            'metadata' => [],
            'created_at' => now(),
        ]);

        return app(PaymentAllocationService::class)->allocateCapture(
            $payment,
            $capture,
            $order,
            $allocateAmount,
            PaymentAllocation::STRATEGY_EXACT_REFERENCE,
            [],
        );
    }

    private function storeReportedRefund(Order $order, int $amountMinor, bool $externalRequired, \DateTimeInterface $occurredAt): Refund
    {
        return Refund::query()->create([
            'tenant_id' => $order->tenant_id,
            'store_id' => $order->store_id,
            'integration_id' => $order->integration_id,
            'order_id' => $order->id,
            'external_id' => fake()->uuid(),
            'source_revision' => 1,
            'currency' => $order->currency,
            'currency_exponent' => $order->currency_exponent,
            'amount_minor' => $amountMinor,
            'external_required' => $externalRequired,
            'provider_ref' => 're_'.fake()->uuid(),
            'status' => 'recorded',
            'occurred_at' => $occurredAt,
            'current_payload_hash' => hash('sha256', fake()->uuid()),
            'updated_at' => now(),
        ]);
    }

    private function bookkeepingOnlyRefund(Order $order, int $amountMinor): Refund
    {
        return $this->storeReportedRefund($order, $amountMinor, false, now()->subMinutes(30));
    }

    private function allocateRefund(Order $order, PaymentAllocation $paymentAllocation, Refund $refund, int $amountMinor, \DateTimeInterface $occurredAt): RefundAllocation
    {
        $refundTransaction = FinancialTransaction::query()->create([
            'tenant_id' => $order->tenant_id,
            'store_id' => $order->store_id,
            'integration_id' => $order->integration_id,
            'payment_id' => $paymentAllocation->payment_id,
            'external_operation_id' => 'ref_'.fake()->uuid(),
            'kind' => 'refund',
            'status' => 'succeeded',
            'currency' => $order->currency,
            'currency_exponent' => $order->currency_exponent,
            'amount_minor' => $amountMinor,
            'occurred_at' => $occurredAt,
            'source_authority' => Integration::SOURCE_INDEPENDENT_PROVIDER,
            'operation_hash' => hash('sha256', fake()->uuid()),
            'metadata' => [],
            'created_at' => now(),
        ]);

        return app(PaymentAllocationService::class)->allocateRefund(
            $refund,
            $refundTransaction,
            $paymentAllocation,
            $amountMinor,
            PaymentAllocation::STRATEGY_EXACT_REFERENCE,
            [],
        );
    }
}
