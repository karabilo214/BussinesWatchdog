<?php

namespace Tests\Feature\Reconciliation;

use App\Exceptions\Payments\AllocationRejected;
use App\Models\DomainOutbox;
use App\Models\FinancialTransaction;
use App\Models\Incident;
use App\Models\Integration;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\ReconciliationDirtySubject;
use App\Models\ReconciliationFinding;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Integrations\ProviderCoverage;
use App\Support\Payments\PaymentAllocationService;
use App\Support\Reconciliation\DirtySubjectMarker;
use App\Support\Reconciliation\DirtySubjectProcessor;
use App\Support\Reconciliation\UnmatchedPaymentScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Notifications\NotificationTestContext;
use Tests\TestCase;

class ProviderCoverageTest extends TestCase
{
    use NotificationTestContext;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_paid_order_without_a_connected_provider_is_unknown_and_opens_no_incident(): void
    {
        $context = $this->connectorOnlyContext();
        $order = $this->order($context, ['paid_marked_at' => now()->subHours(2)]);

        $this->process($context, $order->id);

        $finding = ReconciliationFinding::query()->where('order_id', $order->id)->sole();
        $this->assertSame(ReconciliationFinding::RULE_UNSUPPORTED, $finding->rule_code);
        $this->assertSame(ReconciliationFinding::STATUS_UNKNOWN, $finding->status);
        $this->assertSame(ProviderCoverage::REASON_NOT_CONNECTED, $finding->reason_code);
        $this->assertFalse($finding->run->coverage_snapshot['provider_connected']);
        $this->assertSame(0, Incident::query()->count());
        $this->assertSame(0, DomainOutbox::query()->where('topic', DomainOutbox::TOPIC_INCIDENT_NOTIFICATION_REQUESTED)->count());
    }

    public function test_revoking_the_provider_requeues_orders_and_keeps_open_incidents_unresolved(): void
    {
        $context = $this->context();
        $order = $this->order($context, ['paid_marked_at' => now()->subHours(2)]);
        $this->process($context, $order->id);
        $this->assertSame(Incident::STATE_OPEN, Incident::query()->sole()->state);

        $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->postJson("/api/v1/integrations/{$context['integration']->id}/revoke")
            ->assertOk();

        $subject = ReconciliationDirtySubject::query()->where('subject_id', $order->id)->sole();
        $this->assertSame(ReconciliationDirtySubject::REASON_PROVIDER_COVERAGE_CHANGED, $subject->reason);
        $this->assertTrue(ReconciliationDirtySubject::query()
            ->where('subject_type', ReconciliationDirtySubject::TYPE_STORE_UNMATCHED_PAYMENTS)
            ->where('subject_id', $context['store']->id)
            ->exists());

        app(DirtySubjectProcessor::class)->processDue();

        $latest = ReconciliationFinding::query()->where('order_id', $order->id)->orderByDesc('evaluated_at')->orderByDesc('id')->first();
        $this->assertSame(ReconciliationFinding::STATUS_UNKNOWN, $latest->status);
        $this->assertSame(Incident::STATE_OPEN, Incident::query()->sole()->state);
    }

    public function test_unmatched_payment_scan_needs_a_provider_and_ignores_store_reported_captures(): void
    {
        $context = $this->context();
        $this->capture($context, Integration::SOURCE_STORE_REPORTED);
        $independent = $this->capture($context, Integration::SOURCE_INDEPENDENT_PROVIDER);

        $run = app(UnmatchedPaymentScanner::class)->scan($context['store']);
        $this->assertSame([$independent->id], $run->findings()->get()->pluck('evidence.capture_transaction_id')->all());

        $context['integration']->forceFill(['status' => Integration::STATUS_REVOKED])->save();

        $run = app(UnmatchedPaymentScanner::class)->scan($context['store']);
        $this->assertSame(0, $run->findings()->count());
        $this->assertFalse($run->coverage_snapshot['provider_connected']);
    }

    public function test_store_reported_captures_cannot_be_allocated(): void
    {
        $context = $this->context();
        $order = $this->order($context);
        $capture = $this->capture($context, Integration::SOURCE_STORE_REPORTED);

        try {
            app(PaymentAllocationService::class)->allocateCapture(
                $capture->payment,
                $capture,
                $order,
                18400,
                PaymentAllocation::STRATEGY_EXACT_REFERENCE,
                [],
            );
            $this->fail('Expected the allocation to be rejected.');
        } catch (AllocationRejected $exception) {
            $this->assertSame(PaymentAllocationService::ERROR_SOURCE_NOT_INDEPENDENT, $exception->getMessage());
        }

        $this->assertSame(0, PaymentAllocation::query()->count());
    }

    /**
     * @return array{user: User, tenant: Tenant, store: Store, integration: Integration}
     */
    private function connectorOnlyContext(): array
    {
        $tenant = Tenant::query()->create(['name' => fake()->company(), 'timezone' => 'Europe/Kyiv']);
        $store = Store::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Main Shop',
            'base_url' => 'https://'.fake()->domainName(),
            'timezone' => 'Europe/Kyiv',
            'default_currency' => 'EUR',
        ]);
        $connector = Integration::query()->create([
            'tenant_id' => $tenant->id,
            'store_id' => $store->id,
            'provider' => 'woocommerce',
            'install_id' => fake()->uuid(),
            'mode' => 'live',
            'source_authority' => Integration::SOURCE_STORE_REPORTED,
            'status' => Integration::STATUS_ACTIVE,
            'capabilities' => [],
            'connector_version' => '1.0.0',
            'health' => [],
        ]);

        return $this->context(tenant: $tenant, store: $store, integration: $connector);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function process(array $context, string $orderId): void
    {
        app(DirtySubjectMarker::class)->markOrder($context['tenant']->id, $context['store']->id, $orderId, ReconciliationDirtySubject::REASON_ORDER_EVENT, now());
        app(DirtySubjectProcessor::class)->processDue();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function capture(array $context, string $authority): FinancialTransaction
    {
        $payment = Payment::query()->create([
            'tenant_id' => $context['tenant']->id,
            'store_id' => $context['store']->id,
            'integration_id' => $context['integration']->id,
            'external_id' => fake()->uuid(),
            'intent_ref' => 'pi_'.fake()->uuid(),
            'charge_ref' => 'ch_'.fake()->uuid(),
            'mode' => 'live',
            'currency' => 'EUR',
            'currency_exponent' => 2,
            'status' => 'captured',
            'source_authority' => $authority,
            'source_updated_at' => now()->subDays(2),
            'current_payload_hash' => hash('sha256', fake()->uuid()),
            'metadata' => [],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return FinancialTransaction::query()->create([
            'tenant_id' => $context['tenant']->id,
            'store_id' => $context['store']->id,
            'integration_id' => $context['integration']->id,
            'payment_id' => $payment->id,
            'external_operation_id' => 'cap_'.fake()->uuid(),
            'kind' => 'capture',
            'status' => 'succeeded',
            'currency' => 'EUR',
            'currency_exponent' => 2,
            'amount_minor' => 18400,
            'occurred_at' => now()->subDays(2),
            'source_authority' => $authority,
            'operation_hash' => hash('sha256', fake()->uuid()),
            'metadata' => [],
            'created_at' => now(),
        ]);
    }
}
