<?php

namespace Tests\Feature\Incidents;

use App\Models\FinancialTransaction;
use App\Models\Incident;
use App\Models\IncidentActivity;
use App\Models\Integration;
use App\Models\Order;
use App\Models\OrderRevision;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\ReconciliationDirtySubject;
use App\Models\ReconciliationFinding;
use App\Models\ReconciliationSubjectState;
use App\Models\Refund;
use App\Models\Signal;
use App\Models\Store;
use App\Models\Tenant;
use App\Support\Incidents\MoneyIncidentCorrelator;
use App\Support\Payments\PaymentAllocationService;
use App\Support\Reconciliation\OrderReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\KnownProviderPayment;
use Tests\TestCase;

class MoneyIncidentCorrelatorTest extends TestCase
{
    use KnownProviderPayment;
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

    public function test_a_paid_order_whose_payment_is_not_found_is_a_warning_without_an_amount_even_after_one(): void
    {
        $context = $this->context();
        $order = $this->order($context, ['paid_marked_at' => now()->subMinutes(31)]);
        $correlator = app(MoneyIncidentCorrelator::class);

        [$incident] = $correlator->correlate(app(OrderReconciliationService::class)->evaluate($order));
        $this->assertSame([ReconciliationFinding::RULE_CAPTURE_MISSING, 18400], [$incident->title_code, (int) $incident->verified_discrepancy_minor]);

        $order->forceFill(['transaction_ref' => 'pi_not_at_the_provider'])->save();
        ReconciliationSubjectState::query()->delete();
        [$again] = $correlator->correlate(app(OrderReconciliationService::class)->evaluate($order->fresh()));

        $this->assertSame($incident->id, $again->id);
        $this->assertSame(ReconciliationFinding::RULE_PAYMENT_NOT_MATCHED, $again->title_code);
        $this->assertSame(Incident::SEVERITY_WARNING, $again->severity);
        $this->assertNull($again->verified_discrepancy_minor);

        $unmatched = $this->order($context, ['paid_marked_at' => now()->subMinutes(31), 'transaction_ref' => null, 'external_id' => 'wc-without-payment']);
        [$fresh] = $correlator->correlate(app(OrderReconciliationService::class)->evaluate($unmatched));
        $this->assertSame([ReconciliationFinding::RULE_PAYMENT_NOT_MATCHED, null], [$fresh->title_code, $fresh->verified_discrepancy_minor]);
    }

    public function test_a_cancelled_paid_order_without_a_refund_is_a_review_without_an_amount_that_a_refund_resolves(): void
    {
        $context = $this->context();
        $order = $this->order($context, [
            'status' => 'cancelled',
            'paid_marked_at' => now()->subHours(3),
            'source_updated_at' => now()->subMinutes(61),
            'transaction_ref' => $context['payment']->intent_ref,
        ]);
        app(PaymentAllocationService::class)->allocateCapture($context['payment'], $context['capture'], $order, 18400, PaymentAllocation::STRATEGY_EXACT_REFERENCE, []);
        $correlator = app(MoneyIncidentCorrelator::class);

        $incidents = $correlator->correlate(app(OrderReconciliationService::class)->evaluate($order));
        $review = collect($incidents)->firstWhere('title_code', ReconciliationFinding::RULE_CANCELLED_PAID);

        $this->assertNotNull($review);
        $this->assertSame(['cancelled_paid', Incident::SEVERITY_WARNING, null], [$review->component, $review->severity, $review->verified_discrepancy_minor]);
        $this->assertCount(1, $incidents);

        Refund::query()->create([
            'tenant_id' => $order->tenant_id, 'store_id' => $order->store_id, 'integration_id' => $order->integration_id, 'order_id' => $order->id,
            'external_id' => 'refund-'.fake()->uuid(), 'source_revision' => 1, 'currency' => 'EUR', 'currency_exponent' => 2, 'amount_minor' => 18400,
            'external_required' => true, 'provider_ref' => 're_cancelled', 'status' => 'recorded', 'occurred_at' => now(),
            'current_payload_hash' => hash('sha256', 'refund'), 'updated_at' => now(),
        ]);
        ReconciliationSubjectState::query()->delete();
        $correlator->correlate(app(OrderReconciliationService::class)->evaluate($order->fresh()));

        $this->assertSame(Incident::STATE_RESOLVED, $review->fresh()->state);
    }

    public function test_a_total_changed_after_capture_opens_a_review_without_an_amount(): void
    {
        $context = $this->context();
        $order = $this->order($context, ['paid_marked_at' => now()->subHours(3), 'total_minor' => 23400, 'transaction_ref' => $context['payment']->intent_ref]);
        OrderRevision::query()->create([
            'tenant_id' => $order->tenant_id, 'store_id' => $order->store_id, 'order_id' => $order->id, 'source_revision' => 1,
            'snapshot' => ['data' => ['total_minor' => '18400']], 'payload_hash' => hash('sha256', 'rev1'), 'observed_at' => now()->subHours(3), 'created_at' => now(),
        ]);
        app(PaymentAllocationService::class)->allocateCapture($context['payment'], $context['capture'], $order, 18400, PaymentAllocation::STRATEGY_EXACT_REFERENCE, []);

        $run = app(OrderReconciliationService::class)->evaluate($order);
        $incidents = app(MoneyIncidentCorrelator::class)->correlate($run);

        $this->assertSame([ReconciliationFinding::RULE_ORDER_CHANGED], collect($incidents)->pluck('title_code')->all());
        $this->assertNull($incidents[0]->verified_discrepancy_minor);
        $capture = $run->findings()->where('rule_code', ReconciliationFinding::RULE_CAPTURE_AMOUNT)->sole();
        $this->assertSame([ReconciliationFinding::STATUS_UNKNOWN, 'order_changed_after_capture', -5000], [$capture->status, $capture->reason_code, $capture->difference_minor]);
    }

    public function test_an_amount_published_before_a_rule_change_is_withdrawn_and_the_order_can_be_queued_again(): void
    {
        $context = $this->context();
        $order = $this->order($context, ['paid_marked_at' => now()->subHours(3), 'total_minor' => 23400, 'transaction_ref' => $context['payment']->intent_ref]);
        app(PaymentAllocationService::class)->allocateCapture($context['payment'], $context['capture'], $order, 18400, PaymentAllocation::STRATEGY_EXACT_REFERENCE, []);
        $correlator = app(MoneyIncidentCorrelator::class);

        [$capture] = $correlator->correlate(app(OrderReconciliationService::class)->evaluate($order));
        $this->assertSame([ReconciliationFinding::RULE_CAPTURE_AMOUNT, 5000], [$capture->title_code, (int) $capture->verified_discrepancy_minor]);

        ReconciliationDirtySubject::query()->delete();
        $this->artisan('reconciliation:recheck-open-incidents')->assertSuccessful();
        $this->assertDatabaseHas('reconciliation_dirty_subjects', ['subject_type' => 'order', 'subject_id' => $order->id, 'reason' => 'rule_change_recheck']);

        OrderRevision::query()->create([
            'tenant_id' => $order->tenant_id, 'store_id' => $order->store_id, 'order_id' => $order->id, 'source_revision' => 1,
            'snapshot' => ['data' => ['total_minor' => '18400']], 'payload_hash' => hash('sha256', 'rev1'), 'observed_at' => now()->subHours(3), 'created_at' => now(),
        ]);
        ReconciliationSubjectState::query()->delete();
        $correlator->correlate(app(OrderReconciliationService::class)->evaluate($order->fresh()));

        $capture->refresh();
        $this->assertSame(Incident::STATE_OPEN, $capture->state);
        $this->assertNull($capture->verified_discrepancy_minor);
        $this->assertSame(0, Incident::query()->whereNotNull('verified_discrepancy_minor')->count());
        $this->assertTrue(Incident::query()->where('title_code', ReconciliationFinding::RULE_ORDER_CHANGED)->exists());
    }

    public function test_a_repeated_mismatch_for_the_same_order_and_rule_attaches_instead_of_duplicating(): void
    {
        $context = $this->context();
        $order = $this->order($context, ['paid_marked_at' => now()->subMinutes(31)]);
        $correlator = app(MoneyIncidentCorrelator::class);

        $firstRun = app(OrderReconciliationService::class)->evaluate($order);
        $correlator->correlate($firstRun);

        $unchanged = app(OrderReconciliationService::class)->evaluate($order->fresh());
        $this->assertFalse($unchanged->resultChanged);
        $this->assertSame($firstRun->id, $unchanged->id);

        ReconciliationSubjectState::query()->delete();
        $secondRun = app(OrderReconciliationService::class)->evaluate($order->fresh());
        $this->assertTrue($secondRun->resultChanged);
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

    public function test_same_order_in_different_provider_modes_has_separate_incidents(): void
    {
        $context = $this->context();
        $order = $this->order($context, ['paid_marked_at' => now()->subMinutes(31)]);
        $correlator = app(MoneyIncidentCorrelator::class);

        [$live] = $correlator->correlate(app(OrderReconciliationService::class)->evaluate($order));
        $this->assertSame('live', $live->mode);

        $order->forceFill(['mode' => 'test'])->save();
        $testRun = app(OrderReconciliationService::class)->evaluate($order->fresh());
        $this->assertTrue($testRun->resultChanged);
        [$test] = $correlator->correlate($testRun);

        $this->assertSame('test', $test->mode);
        $this->assertNotSame($live->id, $test->id);
        $this->assertSame(Incident::STATE_OPEN, $live->fresh()->state);
        $this->assertSame(2, Incident::query()->count());
    }

    public function test_unreported_order_mode_stays_unknown(): void
    {
        $context = $this->context();
        $order = $this->order($context, ['paid_marked_at' => now()->subMinutes(31), 'metadata' => ['mode_reported' => false]]);
        $run = app(OrderReconciliationService::class)->evaluate($order);

        $this->assertNull(ReconciliationFinding::query()->where('run_id', $run->id)->firstOrFail()->mode);
        [$incident] = app(MoneyIncidentCorrelator::class)->correlate($run);
        $this->assertNull($incident->mode);
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

    public function test_provider_capture_not_recorded_by_store_opens_and_then_resolves_its_own_incident(): void
    {
        $context = $this->context();
        $order = $this->order($context, ['paid_marked_at' => null, 'status' => 'pending']);
        $context['capture']->forceFill(['occurred_at' => now()->subMinutes(31)])->save();
        app(PaymentAllocationService::class)->allocateCapture(
            $context['payment'], $context['capture'], $order, 18400,
            PaymentAllocation::STRATEGY_EXACT_REFERENCE, [],
        );

        $correlator = app(MoneyIncidentCorrelator::class);
        $run = app(OrderReconciliationService::class)->evaluate($order);
        $opened = $correlator->correlate($run);
        $this->assertCount(1, $opened);
        $this->assertSame('capture_not_recorded', $opened[0]->component);
        $this->assertSame(ReconciliationFinding::RULE_CAPTURE_NOT_RECORDED, $opened[0]->title_code);
        $this->assertSame(Incident::STATE_OPEN, $opened[0]->state);

        $order->forceFill(['paid_marked_at' => now(), 'status' => 'processing'])->save();
        $recovered = app(OrderReconciliationService::class)->evaluate($order->fresh());
        $resolved = $correlator->correlate($recovered);
        $this->assertCount(1, $resolved);
        $this->assertSame($opened[0]->id, $resolved[0]->id);
        $this->assertSame(Incident::STATE_RESOLVED, $resolved[0]->state);
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
        $order = Order::query()->create(array_merge([
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

        return array_key_exists('transaction_ref', $overrides) ? $order : $this->withKnownProviderPayment($order);
    }
}
