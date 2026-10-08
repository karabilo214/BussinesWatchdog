<?php

namespace Tests\Feature\Incidents;

use App\Models\FinancialTransaction;
use App\Models\Incident;
use App\Models\IncidentActivity;
use App\Models\Integration;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\ReconciliationFinding;
use App\Models\Signal;
use App\Models\Store;
use App\Models\Tenant;
use App\Support\Incidents\MoneyIncidentCorrelator;
use App\Support\Payments\PaymentAllocationService;
use App\Support\Reconciliation\OrderReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MoneyIncidentCorrelatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_confirmed_mismatch_opens_a_new_incident_with_a_signal(): void
    {
        $context = $this->context();
        $order = $this->order($context, ['paid_marked_at' => now()->subMinutes(31)]);

        $run = app(OrderReconciliationService::class)->evaluate($order);
        $incidents = app(MoneyIncidentCorrelator::class)->correlate($run);

        $this->assertCount(1, $incidents);
        $incident = $incidents[0];
        $this->assertSame(Incident::STATE_OPEN, $incident->state);
        $this->assertSame('money', $incident->family);
        $this->assertSame('capture', $incident->component);
        $this->assertSame(ReconciliationFinding::RULE_CAPTURE_MISSING, $incident->title_code);
        $this->assertSame(1, $incident->revision);
        $this->assertSame(1, Signal::query()->count());
        $this->assertDatabaseHas('incident_activity', [
            'incident_id' => $incident->id,
            'kind' => IncidentActivity::KIND_CREATED,
        ]);
    }

    public function test_a_repeated_mismatch_for_the_same_order_and_rule_attaches_instead_of_duplicating(): void
    {
        $context = $this->context();
        $order = $this->order($context, ['paid_marked_at' => now()->subMinutes(31)]);
        $correlator = app(MoneyIncidentCorrelator::class);

        $firstRun = app(OrderReconciliationService::class)->evaluate($order);
        $correlator->correlate($firstRun);

        $secondRun = app(OrderReconciliationService::class)->evaluate($order->fresh());
        $incidents = $correlator->correlate($secondRun);

        $this->assertCount(1, $incidents);
        $this->assertSame(1, Incident::query()->count());
        $incident = $incidents[0];
        $this->assertSame(2, $incident->revision);
        $this->assertSame(2, Signal::query()->count());
        $this->assertDatabaseHas('incident_activity', [
            'incident_id' => $incident->id,
            'kind' => IncidentActivity::KIND_SIGNAL_LINKED,
        ]);
    }

    public function test_a_fresh_ok_finding_auto_resolves_the_open_incident(): void
    {
        $context = $this->context();
        $order = $this->order($context, ['paid_marked_at' => now()->subMinutes(31)]);
        $correlator = app(MoneyIncidentCorrelator::class);

        $firstRun = app(OrderReconciliationService::class)->evaluate($order);
        [$incident] = $correlator->correlate($firstRun);

        app(PaymentAllocationService::class)->allocateCapture(
            $context['payment'],
            $context['capture'],
            $order,
            18400,
            PaymentAllocation::STRATEGY_EXACT_REFERENCE,
            [],
        );
        $secondRun = app(OrderReconciliationService::class)->evaluate($order->fresh());
        $resolved = $correlator->correlate($secondRun);

        $this->assertCount(1, $resolved);
        $incident = $resolved[0];
        $this->assertSame($incident->id, $incident->id);
        $this->assertSame(Incident::STATE_RESOLVED, $incident->state);
        $this->assertSame('auto_resolved_fresh_reconciliation_ok', $incident->resolution_reason);
        $this->assertNotNull($incident->last_good_at);
    }

    public function test_an_ok_finding_without_an_active_incident_does_nothing(): void
    {
        $context = $this->context();
        $order = $this->order($context, ['paid_marked_at' => now()->subMinutes(10)]);
        app(PaymentAllocationService::class)->allocateCapture(
            $context['payment'],
            $context['capture'],
            $order,
            18400,
            PaymentAllocation::STRATEGY_EXACT_REFERENCE,
            [],
        );

        $run = app(OrderReconciliationService::class)->evaluate($order->fresh());
        $incidents = app(MoneyIncidentCorrelator::class)->correlate($run);

        $this->assertCount(0, $incidents);
        $this->assertSame(0, Incident::query()->count());
    }

    public function test_a_new_mismatch_within_24_hours_of_resolution_reopens_the_same_incident(): void
    {
        $context = $this->context();
        $order = $this->order($context, ['paid_marked_at' => now()->subMinutes(31)]);
        $correlator = app(MoneyIncidentCorrelator::class);

        $firstRun = app(OrderReconciliationService::class)->evaluate($order);
        [$incident] = $correlator->correlate($firstRun);
        $incidentId = $incident->id;

        $incident->forceFill([
            'state' => Incident::STATE_RESOLVED,
            'resolved_at' => now()->subHours(2),
        ])->save();

        $secondRun = app(OrderReconciliationService::class)->evaluate($order->fresh());
        $reopened = $correlator->correlate($secondRun);

        $this->assertCount(1, $reopened);
        $this->assertSame($incidentId, $reopened[0]->id);
        $this->assertSame(Incident::STATE_OPEN, $reopened[0]->state);
        $this->assertSame(1, Incident::query()->count());
        $this->assertDatabaseHas('incident_activity', [
            'incident_id' => $incidentId,
            'kind' => IncidentActivity::KIND_REOPENED,
        ]);
    }

    public function test_a_new_mismatch_after_24_hours_of_resolution_creates_a_separate_incident(): void
    {
        $context = $this->context();
        $order = $this->order($context, ['paid_marked_at' => now()->subMinutes(31)]);
        $correlator = app(MoneyIncidentCorrelator::class);

        $firstRun = app(OrderReconciliationService::class)->evaluate($order);
        [$incident] = $correlator->correlate($firstRun);
        $firstIncidentId = $incident->id;

        $incident->forceFill([
            'state' => Incident::STATE_RESOLVED,
            'resolved_at' => now()->subHours(25),
        ])->save();

        $secondRun = app(OrderReconciliationService::class)->evaluate($order->fresh());
        $created = $correlator->correlate($secondRun);

        $this->assertCount(1, $created);
        $this->assertNotSame($firstIncidentId, $created[0]->id);
        $this->assertSame(Incident::STATE_OPEN, $created[0]->state);
        $this->assertSame(2, Incident::query()->count());
    }

    /**
     * @return array{tenant: Tenant, store: Store, integration: Integration, payment: Payment, capture: FinancialTransaction}
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

        return compact('tenant', 'store', 'integration', 'payment', 'capture');
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
