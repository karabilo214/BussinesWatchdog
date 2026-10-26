<?php

namespace Tests\Feature\Incidents;

use App\Http\Dto\Incidents\SignalDto;
use App\Models\FinancialTransaction;
use App\Models\Incident;
use App\Models\Integration;
use App\Models\Membership;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ReconciliationFinding;
use App\Models\Signal;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantPermissions;
use App\Support\Auth\TenantRoles;
use App\Support\Incidents\IncidentExplainer;
use App\Support\Incidents\IncidentRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\KnownProviderPayment;
use Tests\TestCase;

class IncidentApiTest extends TestCase
{
    use KnownProviderPayment;
    use RefreshDatabase;

    public function test_triggering_reconciliation_creates_an_incident_visible_via_the_list(): void
    {
        $context = $this->context('admin');
        $order = $this->order($context, ['paid_marked_at' => now()->subMinutes(31)]);

        $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/v1/stores/{$context['store']->id}/reconciliations", [
                'order_ids' => [$order->id],
            ])
            ->assertStatus(202);

        $response = $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->getJson('/api/v1/incidents');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.state', Incident::STATE_OPEN)
            ->assertJsonPath('data.0.family', 'money')
            ->assertJsonPath('data.0.component', 'capture')
            ->assertJsonPath('data.0.order_display_number', '#1001');
    }

    public function test_incident_detail_includes_signals_and_activity(): void
    {
        $context = $this->context('owner');
        $incidentId = $this->triggerIncident($context);

        $response = $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->getJson("/api/v1/incidents/{$incidentId}");

        $response
            ->assertOk()
            ->assertJsonPath('id', $incidentId)
            ->assertJsonCount(1, 'signals')
            ->assertJsonCount(1, 'activity')
            ->assertJsonPath('activity.0.kind', 'created')
            ->assertJsonPath('currency', 'EUR')
            ->assertJsonPath('currency_exponent', 2)
            ->assertJsonPath('order_display_number', '#1001')
            ->assertJsonPath('active_suppression', null)
            ->assertJsonCount(1, 'findings')
            ->assertJsonPath('findings.0.rule_code', 'MONEY_CAPTURE_MISSING')
            ->assertJsonPath('findings.0.order_display_number', '#1001')
            ->assertJsonPath('findings.0.expected_minor', '18400');
    }

    public function test_payment_attempt_name_comes_only_from_the_same_tenant_store_and_gateway(): void
    {
        $context = $this->context('viewer');
        $otherStore = $this->context('owner', $context['tenant']);
        $foreign = $this->context('owner');
        foreach ([$otherStore, $foreign] as $other) {
            $this->order($other, ['gateway' => 'merchant_custom', 'metadata' => ['gateway_name' => 'Private shop name'], 'source_updated_at' => now()]);
        }
        $this->order($context, ['gateway' => 'different_code', 'metadata' => ['gateway_name' => 'Different method'], 'source_updated_at' => now()]);
        $signal = new Signal(['tenant_id' => $context['tenant']->id, 'store_id' => $context['store']->id, 'signal_type' => Signal::TYPE_PAYMENT_ATTEMPTS, 'evidence' => ['payment_method' => 'merchant_custom', 'failure_streak' => 3]]);
        $dto = app(SignalDto::class);
        $this->assertNull($dto->toArray($signal)['evidence']['payment_method_name']);

        $this->order($context, ['gateway' => 'merchant_custom', 'metadata' => ['gateway_name' => 'Pay at pickup']]);
        $this->order($context, ['gateway' => 'merchant_custom', 'metadata' => [], 'source_updated_at' => now()]);
        $evidence = $dto->toArray($signal)['evidence'];
        $this->assertSame('Pay at pickup', $evidence['payment_method_name']);
        $this->assertSame('merchant_custom', $evidence['payment_method']);
        $this->assertSame(3, $evidence['failure_streak']);
        $this->assertArrayNotHasKey('payment_method_name', $signal->evidence, 'display enrichment never changes stored signal evidence');
    }

    public function test_an_unconfirmed_capture_is_explained_from_its_evidence_with_steps_for_a_known_reference(): void
    {
        $context = $this->context('owner');
        $context['integration']->forceFill(['last_successful_sync_at' => now()->subMinutes(5)])->save();
        $incidentId = $this->triggerIncident($context, ['gateway' => 'stripe']);
        $reference = Order::query()->value('transaction_ref');

        $explanation = $this->detail($context, $incidentId)->json('explanation');
        $this->assertCodesKnown($explanation);

        $this->assertSame(['capture_unconfirmed', 'with_reference', 'detected'], [$explanation['scenario'], $explanation['variant'], $explanation['status']]);
        $this->assertSame(['order' => '#1001', 'provider' => 'stripe', 'reference' => $reference, 'amount' => ['minor' => '18400', 'currency' => 'EUR', 'exponent' => 2]], $explanation['params']);
        $this->assertSame(['store_order', 'paid_mark', 'payment_reference', 'provider_payment', 'successful_capture', 'last_provider_sync', 'data_completeness'], array_column($explanation['evidence'], 'item'));
        $this->assertSame(['found', 'not_confirmed', 'full'], [$explanation['evidence'][3]['value'], $explanation['evidence'][4]['value'], $explanation['evidence'][6]['value']]);
        $this->assertSame(['capture_pending'], $explanation['causes'], 'only the cause that fits: the payment exists and waits; no "wait for the sync" when the data is complete');
        $this->assertSame(['open_payment_by_reference', 'check_captured_not_authorized', 'compare_reference', 'recheck'], $explanation['steps']);
        $this->assertSame(['no_recharge', 'capture_linked_or_unmarked'], [$explanation['warning'], $explanation['auto_close']]);
        $this->assertSame(['state' => 'fresh', 'reason' => null], array_intersect_key($explanation['observation'], ['state' => 1, 'reason' => 1]));
        $this->assertSame($reference, $explanation['technical']['payment_intent']);
        $this->assertSame('MONEY_CAPTURE_MISSING', $explanation['technical']['rule_code']);
    }

    public function test_without_a_payment_reference_the_steps_start_at_the_payment_module_and_lost_access_makes_it_unverifiable(): void
    {
        $context = $this->context('owner');
        $context['integration']->forceFill(['last_successful_sync_at' => now()->subMinutes(5)])->save();
        $incidentId = $this->triggerIncident($context, ['gateway' => 'stripe', 'transaction_ref' => null]);

        $explanation = $this->detail($context, $incidentId)->json('explanation');
        $this->assertCodesKnown($explanation);
        $this->assertSame(['capture_unconfirmed', 'without_reference'], [$explanation['scenario'], $explanation['variant']]);
        $this->assertArrayNotHasKey('reference', $explanation['params']);
        $this->assertSame('missing', $explanation['evidence'][2]['value']);
        $this->assertSame(['reference_missing', 'marked_paid_early'], $explanation['causes']);
        $this->assertSame('check_payment_module_data', $explanation['steps'][0]);

        $context['integration']->forceFill(['status' => Integration::STATUS_DEGRADED, 'health' => ['key_rejected_at' => now()->toJSON()]])->save();
        $explanation = $this->detail($context, $incidentId)->json('explanation');
        $this->assertCodesKnown($explanation);
        $this->assertSame(['unverifiable', 'unavailable', 'provider_access_lost'], [$explanation['status'], $explanation['observation']['state'], $explanation['observation']['reason']], 'found earlier, cannot be checked again now: neither fixed nor freshly confirmed');
        $this->assertSame('restore_provider_access', $explanation['steps'][0]);
        $this->assertContains('provider_data_incomplete', $explanation['causes']);
    }

    public function test_closing_by_hand_is_not_a_confirmation_and_hidden_amounts_stay_hidden(): void
    {
        $context = $this->context('owner');
        $incidentId = $this->triggerIncident($context, ['gateway' => 'stripe']);
        $this->assertSame('provider_not_synced_yet', $this->detail($context, $incidentId)->json('explanation.observation.reason'));
        $this->assertContains('provider_data_incomplete', $this->detail($context, $incidentId)->json('explanation.causes'));

        $this->actingAs($context['user'])->withSession(['active_tenant_id' => $context['tenant']->id])->withHeader('If-Match', '"1"')
            ->postJson("/api/v1/incidents/{$incidentId}/resolve", ['reason' => 'Checked by phone.'])->assertOk();
        $explanation = $this->detail($context, $incidentId)->json('explanation');
        $this->assertCodesKnown($explanation);
        $this->assertSame(['closed_by_user', 'closed_by_user'], [$explanation['status'], $explanation['outcome']['code']]);

        $explanation = app(IncidentExplainer::class)->explain(Incident::query()->findOrFail($incidentId), false);
        $this->assertCodesKnown($explanation);
        $this->assertArrayNotHasKey('amount', $explanation['params']);
        $this->assertArrayNotHasKey('amount', $explanation['evidence'][0]['params']);
    }

    public function test_a_refund_marked_in_shopware_with_a_payment_app_without_refunds_points_to_the_provider_dashboard(): void
    {
        $context = $this->context('owner');
        $context['integration']->forceFill(['last_successful_sync_at' => now()->subMinutes(5)])->save();
        $order = $this->order($context, ['gateway' => 'stripe_shopware_payment_payment_handler_card', 'metadata' => ['refund_state' => 'full', 'provider_refunds_synced' => false]]);
        $incident = $this->incidentFor($context, $order, ReconciliationFinding::RULE_REFUND_MISSING, ['refund_expected_minor' => null, 'refund_actual_minor' => 0, 'reason_code' => 'refund_missing_store_state_full']);

        $explanation = app(IncidentExplainer::class)->explain($incident);
        $this->assertCodesKnown($explanation);
        $this->assertSame(['refund_unconfirmed', 'payment_app_without_refunds'], [$explanation['scenario'], $explanation['variant']]);
        $this->assertSame(['full_no_amount', 'none'], [$explanation['evidence'][1]['value'], $explanation['evidence'][2]['value']]);
        $this->assertSame(['refund_marked_only', 'refund_failed_at_provider'], $explanation['causes']);
        $this->assertSame(['check_refund_at_provider', 'refund_in_provider_dashboard', 'recheck'], $explanation['steps']);
        $this->assertSame('no_double_refund', $explanation['warning']);
    }

    public function test_a_source_that_stopped_updating_explains_the_reason_and_the_first_check(): void
    {
        $context = $this->context('owner');
        $woo = Integration::query()->create([
            'tenant_id' => $context['tenant']->id, 'store_id' => $context['store']->id, 'provider' => 'woocommerce_api', 'mode' => 'live',
            'source_authority' => Integration::SOURCE_STORE_REPORTED, 'status' => Integration::STATUS_ACTIVE, 'capabilities' => [], 'connector_version' => '1', 'health' => [],
            'last_successful_sync_at' => now()->subHours(3),
        ]);
        $signal = Signal::query()->create([
            'tenant_id' => $context['tenant']->id, 'store_id' => $context['store']->id, 'signal_type' => Signal::TYPE_CONNECTOR_FRESHNESS, 'family' => 'integration', 'component' => 'stale',
            'dedupe_key' => 'test-freshness', 'severity' => Signal::SEVERITY_WARNING, 'confidence' => Signal::CONFIDENCE_OBSERVED, 'rule_version' => 'v1', 'config_version' => 1,
            'observed_start' => now()->subHours(3), 'observed_end' => now(), 'evidence' => ['integration_id' => $woo->id, 'provider' => 'woocommerce_api', 'state' => 'stale', 'reason' => 'api_key_rejected', 'last_heartbeat_at' => null],
            'data_quality' => [], 'detected_at' => now(),
        ]);
        $incident = app(IncidentRecorder::class)->openOrAttach($context['tenant']->id, $context['store']->id, 'freshness-test', ['family' => 'integration', 'component' => 'connector', 'title_code' => 'INTEGRATION_STALE', 'severity' => Incident::SEVERITY_WARNING, 'first_bad_at' => now()], $signal, 'connector_stale');

        $explanation = app(IncidentExplainer::class)->explain($incident);
        $this->assertCodesKnown($explanation);
        $this->assertSame(['source_unavailable', 'stale', 'detected'], [$explanation['scenario'], $explanation['variant'], $explanation['status']]);
        $this->assertSame(['key_revoked_or_changed'], $explanation['causes']);
        $this->assertSame(['replace_key', 'check_connection'], $explanation['steps']);
        $this->assertSame(['unavailable', 'api_key_rejected'], [$explanation['observation']['state'], $explanation['observation']['reason']]);
        $this->assertSame('at', $explanation['evidence'][3]['value'], 'the last successful sync is shown');
    }

    public function test_a_stopped_browser_check_names_the_step_and_never_suggests_a_payment(): void
    {
        $context = $this->context('owner');
        $signal = Signal::query()->create([
            'tenant_id' => $context['tenant']->id, 'store_id' => $context['store']->id, 'signal_type' => Signal::TYPE_BROWSER_CHECK, 'family' => 'checkout', 'component' => 'failed',
            'dedupe_key' => 'test-browser', 'severity' => Signal::SEVERITY_WARNING, 'confidence' => Signal::CONFIDENCE_OBSERVED, 'rule_version' => 'v1', 'config_version' => 1,
            'observed_start' => now(), 'observed_end' => now(), 'evidence' => ['run_status' => 'failed', 'error_code' => 'site_failure', 'failed_step' => 'cart'], 'data_quality' => [], 'detected_at' => now(),
        ]);
        $incident = app(IncidentRecorder::class)->openOrAttach($context['tenant']->id, $context['store']->id, 'browser-test', ['family' => 'checkout', 'component' => 'payment_form', 'title_code' => 'CHECKOUT_FLOW_FAILED', 'severity' => Incident::SEVERITY_WARNING, 'first_bad_at' => now(), 'last_good_at' => now()->subDay()], $signal, 'browser_check_failed');

        $explanation = app(IncidentExplainer::class)->explain($incident);
        $this->assertCodesKnown($explanation);
        $this->assertSame(['browser_check_stopped', 'step_known'], [$explanation['scenario'], $explanation['variant']]);
        $this->assertSame(['step' => 'cart', 'error' => 'site_failure'], $explanation['params']);
        $this->assertSame(['repeat_path_manually', 'check_recent_changes', 'run_check_again'], $explanation['steps'], 'no run to open: the steps do not point to it');
        $this->assertSame(['browser_check_no_payment', 'scheduled_checks_pass', null], [$explanation['warning'], $explanation['auto_close'], $explanation['observation']]);
        $this->assertSame(['step', 'none', 'error', 'not_available', 'at'], array_column($explanation['evidence'], 'value'));
    }

    public function test_a_refund_made_only_at_the_provider_is_explained_as_money_already_returned(): void
    {
        $context = $this->context('owner');
        $context['integration']->forceFill(['last_successful_sync_at' => now()->subMinutes(5)])->save();
        $order = $this->order($context, ['gateway' => 'stripe', 'metadata' => ['refund_state' => 'none']]);
        $incident = $this->incidentFor($context, $order, ReconciliationFinding::RULE_REFUND_EXTRA, ['refund_expected_minor' => 0, 'refund_actual_minor' => 500, 'reason_code' => 'refund_extra_store_state_none']);

        $explanation = app(IncidentExplainer::class)->explain($incident);
        $this->assertCodesKnown($explanation);
        $this->assertSame(['refund_not_recorded', 'without_reference'], [$explanation['scenario'], $explanation['variant']]);
        $this->assertSame(['minor' => '500', 'currency' => 'EUR', 'exponent' => 2], $explanation['params']['actual']);
        $this->assertSame(['amount', 'unknown', 'none'], [$explanation['evidence'][1]['value'], $explanation['evidence'][2]['value'], $explanation['evidence'][3]['value']]);
        $this->assertSame(['refund_made_at_provider', 'store_refund_notification_not_processed'], $explanation['causes']);
        $this->assertSame(['find_refund_in_provider', 'check_who_refunded', 'record_refund_in_store_without_sending', 'recheck'], $explanation['steps']);
        $this->assertSame(['refund_already_sent', 'store_records_refund'], [$explanation['warning'], $explanation['auto_close']]);
    }

    public function test_a_smaller_capture_and_several_captures_get_their_own_causes_and_steps(): void
    {
        $context = $this->context('owner');
        $order = $this->order($context, ['gateway' => 'stripe']);
        $amount = $this->incidentFor($context, $order, ReconciliationFinding::RULE_CAPTURE_AMOUNT, ['gross_minor' => 18400, 'captured_minor' => 15000, 'expected_minor' => 18400, 'actual_minor' => 15000, 'difference_minor' => -3400, 'reason_code' => 'capture_amount_mismatch', 'evidence' => ['capture_operation_ids' => ['ch_part']]]);

        $explanation = app(IncidentExplainer::class)->explain($amount);
        $this->assertCodesKnown($explanation);
        $this->assertSame(['capture_amount', 'less'], [$explanation['scenario'], $explanation['variant']]);
        $this->assertSame('3400', $explanation['params']['difference']['minor']);
        $this->assertSame(['partial_capture', 'second_capture_pending', 'order_edited_after_payment'], $explanation['causes']);
        $this->assertSame('settle_difference_without_recharge', $explanation['steps'][2]);
        $this->assertSame(['ids', 'not_detected'], [$explanation['evidence'][2]['value'], $explanation['evidence'][3]['value']]);
        $this->assertSame('no_recharge', $explanation['warning']);

        $second = $this->order($context, ['gateway' => 'stripe', 'display_number' => '#1002']);
        $multiple = $this->incidentFor($context, $second, ReconciliationFinding::RULE_MULTIPLE_CAPTURES, ['reason_code' => 'multiple_distinct_captures', 'evidence' => ['capture_operation_ids' => ['ch_a', 'ch_b']]]);
        $explanation = app(IncidentExplainer::class)->explain($multiple);
        $this->assertCodesKnown($explanation);
        $this->assertSame(['multiple_captures', '2'], [$explanation['scenario'], $explanation['params']['count']]);
        $this->assertSame('ch_a, ch_b', $explanation['evidence'][2]['params']['ids']);
        $this->assertSame(['no_auto_refund', 'refund_duplicate_once'], [$explanation['warning'], $explanation['steps'][2]]);
    }

    public function test_a_payment_without_an_order_is_found_money_not_a_loss(): void
    {
        $context = $this->context('owner');
        $context['integration']->forceFill(['last_successful_sync_at' => now()->subMinutes(5)])->save();
        $payment = Payment::query()->create([
            'tenant_id' => $context['tenant']->id, 'store_id' => $context['store']->id, 'integration_id' => $context['integration']->id, 'external_id' => 'pi_orphan', 'intent_ref' => 'pi_orphan',
            'mode' => 'live', 'currency' => 'EUR', 'currency_exponent' => 2, 'status' => 'captured', 'source_authority' => Integration::SOURCE_INDEPENDENT_PROVIDER, 'source_updated_at' => now(),
            'current_payload_hash' => hash('sha256', 'pi_orphan'), 'metadata' => [], 'created_at' => now(), 'updated_at' => now(),
        ]);
        $capture = FinancialTransaction::query()->create([
            'tenant_id' => $context['tenant']->id, 'store_id' => $context['store']->id, 'integration_id' => $context['integration']->id, 'payment_id' => $payment->id,
            'external_operation_id' => 'ch_orphan', 'kind' => 'capture', 'status' => 'succeeded', 'currency' => 'EUR', 'currency_exponent' => 2, 'amount_minor' => 4200,
            'occurred_at' => now()->subDays(2), 'source_authority' => Integration::SOURCE_INDEPENDENT_PROVIDER, 'operation_hash' => hash('sha256', 'ch_orphan'), 'metadata' => [],
        ]);
        $incident = $this->incidentFor($context, null, ReconciliationFinding::RULE_PAYMENT_WITHOUT_ORDER, ['payment_id' => $payment->id, 'actual_minor' => 4200, 'reason_code' => 'unmatched_capture_awaiting_review', 'evidence' => ['capture_transaction_id' => $capture->id, 'occurred_at' => now()->subDays(2)->toJSON()]]);

        $explanation = app(IncidentExplainer::class)->explain($incident);
        $this->assertCodesKnown($explanation);
        $this->assertSame(['payment_without_order', 'with_reference'], [$explanation['scenario'], $explanation['variant']]);
        $this->assertSame(['stripe', 'ch_orphan', '4200'], [$explanation['params']['provider'], $explanation['params']['capture'], $explanation['params']['amount']['minor']]);
        $this->assertSame('open_capture_in_provider', $explanation['steps'][0]);
        $this->assertSame(['payment_not_lost', 'payment_linked', 'fresh'], [$explanation['warning'], $explanation['auto_close'], $explanation['observation']['state']]);
    }

    public function test_list_returns_newest_first_with_a_cursor_and_filters_several_states(): void
    {
        $context = $this->context('viewer');
        $ids = [];

        foreach (['resolved', 'open', 'acknowledged', 'open'] as $index => $state) {
            $ids[] = Incident::query()->create([
                'tenant_id' => $context['tenant']->id,
                'store_id' => $context['store']->id,
                'family' => 'integration',
                'component' => 'connector',
                'fingerprint' => "fp-{$index}",
                'state' => $state,
                'severity' => 'warning',
                'title_code' => 'INTEGRATION_STALE',
                'first_seen_at' => now(),
                'last_seen_at' => now(),
                'revision' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ])->id;
        }

        $request = $this->actingAs($context['user'])->withSession(['active_tenant_id' => $context['tenant']->id]);

        $first = $request->getJson('/api/v1/incidents?limit=2')
            ->assertOk()
            ->assertJsonPath('data.0.id', $ids[3])
            ->assertJsonPath('data.1.id', $ids[2]);

        $request->getJson('/api/v1/incidents?limit=2&cursor='.urlencode((string) $first->json('next_cursor')))
            ->assertOk()
            ->assertJsonPath('data.0.id', $ids[1])
            ->assertJsonPath('data.1.id', $ids[0])
            ->assertJsonPath('next_cursor', null);

        $active = $request->getJson('/api/v1/incidents?state=open,acknowledged')->assertOk();

        $this->assertSame([$ids[3], $ids[2], $ids[1]], array_column($active->json('data'), 'id'));

        $request->getJson('/api/v1/incidents?state=open,closed')
            ->assertUnprocessable();
    }

    public function test_operator_can_acknowledge_but_viewer_cannot(): void
    {
        $context = $this->context('operator');
        $incidentId = $this->triggerIncident($context);

        $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->withHeader('If-Match', '"1"')
            ->postJson("/api/v1/incidents/{$incidentId}/acknowledge")
            ->assertOk()
            ->assertJsonPath('state', Incident::STATE_ACKNOWLEDGED);

        $viewerContext = $this->context('viewer', $context['tenant'], $context['store'], $context['integration']);

        $this->actingAs($viewerContext['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->withHeader('If-Match', '"2"')
            ->postJson("/api/v1/incidents/{$incidentId}/acknowledge")
            ->assertForbidden();
    }

    public function test_operator_can_resolve_with_a_reason(): void
    {
        $context = $this->context('operator');
        $incidentId = $this->triggerIncident($context);

        $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->postJson("/api/v1/incidents/{$incidentId}/resolve", [
                'reason' => 'Matched the capture manually.',
            ])
            ->assertStatus(428);

        $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->withHeader('If-Match', '"5"')
            ->postJson("/api/v1/incidents/{$incidentId}/resolve", [
                'reason' => 'Matched the capture manually.',
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'version_conflict');

        $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->withHeader('If-Match', '"1"')
            ->postJson("/api/v1/incidents/{$incidentId}/resolve", [
                'reason' => 'Matched the capture manually.',
            ])
            ->assertOk()
            ->assertHeader('ETag', '"2"')
            ->assertJsonPath('state', Incident::STATE_RESOLVED)
            ->assertJsonPath('resolution_reason', 'Matched the capture manually.');

        $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->withHeader('If-Match', '"2"')
            ->postJson("/api/v1/incidents/{$incidentId}/resolve", [
                'reason' => 'Trying again.',
            ])
            ->assertStatus(409);
    }

    public function test_operator_can_comment(): void
    {
        $context = $this->context('operator');
        $incidentId = $this->triggerIncident($context);

        $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->postJson("/api/v1/incidents/{$incidentId}/comments", [
                'text' => 'Looking into this now.',
            ])
            ->assertCreated()
            ->assertJsonPath('kind', 'comment')
            ->assertJsonPath('data.text', 'Looking into this now.');
    }

    public function test_admin_can_snooze_and_revoke_a_suppression_but_operator_cannot_snooze(): void
    {
        $context = $this->context('admin');
        $incidentId = $this->triggerIncident($context);

        $response = $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->postJson("/api/v1/incidents/{$incidentId}/snooze", [
                'until' => now()->addDays(3)->toJSON(),
                'reason' => 'Known issue, already being handled.',
            ]);
        $response->assertCreated();
        $suppressionId = $response->json('id');

        $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->getJson("/api/v1/incidents/{$incidentId}")
            ->assertOk()
            ->assertJsonPath('active_suppression.id', $suppressionId)
            ->assertJsonPath('state', Incident::STATE_OPEN);

        $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->postJson("/api/v1/suppressions/{$suppressionId}/revoke")
            ->assertOk()
            ->assertJsonPath('id', $suppressionId)
            ->assertJsonPath('revoked_at', fn ($value) => $value !== null);

        $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->getJson("/api/v1/incidents/{$incidentId}")
            ->assertJsonPath('active_suppression', null);

        $operatorContext = $this->context('operator', $context['tenant'], $context['store'], $context['integration']);

        $this->actingAs($operatorContext['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->postJson("/api/v1/incidents/{$incidentId}/snooze", [
                'until' => now()->addDays(3)->toJSON(),
                'reason' => 'Operator should not be able to do this.',
            ])
            ->assertForbidden();
    }

    public function test_overview_sums_verified_discrepancies_per_mode_currency_and_component_only(): void
    {
        $context = $this->context('admin');
        $real = Incident::query()->findOrFail($this->triggerIncident($context));
        $make = function (array $attributes) use ($context): Incident {
            return Incident::query()->create(array_merge([
                'tenant_id' => $context['tenant']->id,
                'store_id' => $context['store']->id,
                'family' => 'money',
                'component' => 'capture',
                'fingerprint' => (string) Str::uuid(),
                'state' => 'open',
                'severity' => 'warning',
                'title_code' => 'MONEY_CAPTURE_MISSING',
                'first_seen_at' => now(),
                'last_seen_at' => now(),
                'revision' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ], $attributes));
        };
        $make(['currency' => 'EUR', 'mode' => 'live', 'verified_discrepancy_minor' => 9000000000000000000]);
        $make(['currency' => 'EUR', 'mode' => 'test', 'verified_discrepancy_minor' => 42]);
        $make(['currency' => 'EUR', 'verified_discrepancy_minor' => 500, 'component' => 'refund', 'state' => 'acknowledged', 'title_code' => 'MONEY_REFUND_MISSING']);
        $make(['currency' => 'USD', 'verified_discrepancy_minor' => 1000]);
        $make(['currency' => null, 'verified_discrepancy_minor' => null]);
        $make(['currency' => 'EUR', 'verified_discrepancy_minor' => 7000, 'state' => 'resolved']);
        $make(['family' => 'integration', 'component' => 'connector', 'title_code' => 'INTEGRATION_STALE', 'severity' => 'critical', 'currency' => null, 'verified_discrepancy_minor' => null]);

        $foreign = $this->context('owner');
        Incident::query()->create([
            'tenant_id' => $foreign['tenant']->id, 'store_id' => $foreign['store']->id, 'family' => 'money', 'component' => 'capture',
            'fingerprint' => 'foreign', 'state' => 'open', 'severity' => 'warning', 'title_code' => 'MONEY_CAPTURE_MISSING',
            'currency' => 'EUR', 'verified_discrepancy_minor' => 1, 'first_seen_at' => now(), 'last_seen_at' => now(), 'revision' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->getJson('/api/v1/overview')
            ->assertOk()
            ->assertJsonPath('incidents.active', 7)
            ->assertJsonPath('incidents.by_severity.critical', 1)
            ->assertJsonPath('incidents.by_severity.warning', 6)
            ->assertJsonCount(5, 'incidents.latest')
            ->assertJsonPath('unknown_amount_incidents', 1);

        $groups = collect($response->json('discrepancies'))->keyBy(fn (array $row): string => ($row['mode'] ?? 'unknown').'/'.$row['currency'].'/'.$row['component']);

        $this->assertSame(['live/EUR/capture', 'test/EUR/capture', 'unknown/EUR/refund', 'unknown/USD/capture'], $groups->keys()->sort()->values()->all());
        $this->assertSame(bcadd('9000000000000000000', (string) $real->verified_discrepancy_minor), $groups['live/EUR/capture']['total_minor']);
        $this->assertSame(2, $groups['live/EUR/capture']['incident_count']);
        $this->assertSame('2', (string) $groups['live/EUR/capture']['currency_exponent']);
        $this->assertSame('42', $groups['test/EUR/capture']['total_minor']);
        $this->assertSame('500', $groups['unknown/EUR/refund']['total_minor']);
        $this->assertSame('1000', $groups['unknown/USD/capture']['total_minor']);
        $this->assertNull($groups['unknown/USD/capture']['currency_exponent']);
    }

    public function test_owner_can_hide_discrepancy_amounts_from_a_member_at_the_api_boundary(): void
    {
        $owner = $this->context('owner');
        $admin = $this->context('admin', $owner['tenant'], $owner['store'], $owner['integration']);
        $incidentId = $this->triggerIncident($owner);
        $finding = ReconciliationFinding::query()->where('tenant_id', $owner['tenant']->id)->whereNotNull('expected_minor')->firstOrFail();
        $this->assertSame('18400', (string) $finding->expected_minor);
        $withoutAmounts = TenantPermissions::preset(TenantRoles::ADMIN, false);

        $this->actingAs($admin['user'])->withSession(['active_tenant_id' => $owner['tenant']->id])
            ->patchJson("/api/v1/memberships/{$owner['user']->id}", ['permissions' => $withoutAmounts])->assertForbidden();

        $this->actingAs($owner['user'])->withSession(['active_tenant_id' => $owner['tenant']->id])
            ->patchJson("/api/v1/memberships/{$admin['user']->id}", ['permissions' => $withoutAmounts])
            ->assertOk()->assertJsonPath('role', 'admin')->assertJsonPath('permissions', $withoutAmounts);
        $this->assertDatabaseHas('audit_log', ['tenant_id' => $owner['tenant']->id, 'action' => 'membership.permissions_changed', 'entity_id' => $admin['user']->id]);

        $this->actingAs($admin['user'])->withSession(['active_tenant_id' => $owner['tenant']->id])
            ->getJson('/api/v1/money-visibility')->assertOk()->assertJsonPath('can_view_money_amounts', false);
        $this->getJson('/api/v1/overview')->assertOk()
            ->assertJsonPath('money_amounts_hidden', true)
            ->assertJsonPath('discrepancies.0.total_minor', null)
            ->assertJsonPath('incidents.latest.0.verified_discrepancy_minor', null);
        $this->getJson('/api/v1/incidents')->assertOk()
            ->assertJsonPath('data.0.money_amounts_hidden', true)
            ->assertJsonPath('data.0.verified_discrepancy_minor', null);
        $this->getJson("/api/v1/incidents/{$incidentId}")->assertOk()
            ->assertJsonPath('verified_discrepancy_minor', null)
            ->assertJsonPath('findings.0.expected_minor', null)
            ->assertJsonPath('findings.0.evidence', []);
        $this->getJson("/api/v1/stores/{$owner['store']->id}/findings")->assertOk()
            ->assertJsonPath('data.0.money_amounts_hidden', true)
            ->assertJsonPath('data.0.expected_minor', null);
        $this->getJson("/api/v1/orders/{$finding->order_id}")->assertOk()
            ->assertJsonPath('findings.0.expected_minor', null);

        $this->actingAs($owner['user'])->withSession(['active_tenant_id' => $owner['tenant']->id])
            ->getJson("/api/v1/incidents/{$incidentId}")->assertOk()
            ->assertJsonPath('findings.0.expected_minor', '18400');

        $this->patchJson("/api/v1/memberships/{$admin['user']->id}", ['permissions' => [...$withoutAmounts, TenantPermissions::MONEY_VIEW]])->assertOk();
        $this->actingAs($admin['user'])->withSession(['active_tenant_id' => $owner['tenant']->id])
            ->getJson("/api/v1/incidents/{$incidentId}")->assertOk()
            ->assertJsonPath('findings.0.expected_minor', '18400');
    }

    public function test_user_cannot_read_or_act_on_a_foreign_tenant_incident(): void
    {
        $context = $this->context('admin');
        $incidentId = $this->triggerIncident($context);
        $foreignContext = $this->context('admin');

        $this->actingAs($foreignContext['user'])
            ->withSession(['active_tenant_id' => $foreignContext['tenant']->id])
            ->getJson("/api/v1/incidents/{$incidentId}")
            ->assertNotFound();

        $this->actingAs($foreignContext['user'])
            ->withSession(['active_tenant_id' => $foreignContext['tenant']->id])
            ->withHeader('If-Match', '"1"')
            ->postJson("/api/v1/incidents/{$incidentId}/acknowledge")
            ->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function triggerIncident(array $context, array $overrides = []): string
    {
        $order = $this->order($context, ['paid_marked_at' => now()->subMinutes(31)] + $overrides);

        $response = $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/v1/stores/{$context['store']->id}/reconciliations", [
                'order_ids' => [$order->id],
            ]);

        /** @var Incident $incident */
        $incident = Incident::query()->firstOrFail();

        return $incident->id;
    }

    /**
     * @param  array<string, mixed>  $explanation
     */
    private function assertCodesKnown(array $explanation): void
    {
        foreach (['causes' => 'cause', 'steps' => 'step'] as $field => $group) {
            $this->assertSame([], array_values(array_diff($explanation[$field] ?? [], IncidentExplainer::TEXT_CODES[$group])), $group);
        }

        foreach (['warning', 'auto_close'] as $group) {
            if (($explanation[$group] ?? null) !== null) {
                $this->assertContains($explanation[$group], IncidentExplainer::TEXT_CODES[$group], $group);
            }
        }
    }

    private function detail(array $context, string $incidentId): TestResponse
    {
        return $this->actingAs($context['user'])->withSession(['active_tenant_id' => $context['tenant']->id])->getJson("/api/v1/incidents/{$incidentId}")->assertOk();
    }

    /**
     * @param  array<string, mixed>  $finding
     */
    private function incidentFor(array $context, ?Order $order, string $rule, array $finding): Incident
    {
        $runId = (string) Str::uuid7();
        DB::table('reconciliation_runs')->insert(['id' => $runId, 'tenant_id' => $context['tenant']->id, 'store_id' => $context['store']->id, 'algorithm_version' => 'test', 'config_version' => 1, 'currency' => 'EUR', 'scope' => '{}', 'coverage_snapshot' => '{}', 'counters' => '{}', 'status' => 'completed', 'started_at' => now(), 'finished_at' => now(), 'created_at' => now()]);
        $stored = ReconciliationFinding::query()->create($finding + [
            'tenant_id' => $context['tenant']->id, 'store_id' => $context['store']->id, 'run_id' => $runId, 'order_id' => $order?->id, 'rule_code' => $rule,
            'status' => ReconciliationFinding::STATUS_MISMATCH, 'mode' => 'live', 'currency' => 'EUR', 'currency_exponent' => 2, 'evidence' => [], 'evaluated_at' => now(),
        ]);
        $signal = Signal::query()->create([
            'tenant_id' => $context['tenant']->id, 'store_id' => $context['store']->id, 'signal_type' => Signal::TYPE_RECONCILIATION_FINDING, 'family' => 'money', 'component' => 'refund',
            'dedupe_key' => 'test-'.$stored->id, 'severity' => Signal::SEVERITY_WARNING, 'confidence' => Signal::CONFIDENCE_OBSERVED, 'rule_version' => 'v1', 'config_version' => 1,
            'finding_id' => $stored->id, 'currency' => 'EUR', 'observed_start' => now(), 'observed_end' => now(), 'evidence' => [], 'data_quality' => [], 'detected_at' => now(),
        ]);

        return app(IncidentRecorder::class)->openOrAttach($context['tenant']->id, $context['store']->id, 'explain-test-'.$stored->id, ['family' => 'money', 'component' => 'refund', 'title_code' => $rule, 'severity' => Incident::SEVERITY_WARNING, 'currency' => 'EUR', 'mode' => 'live', 'first_bad_at' => now()], $signal, 'finding');
    }

    /**
     * @return array{user: User, tenant: Tenant, store: Store, integration: Integration}
     */
    private function context(string $role, ?Tenant $tenant = null, ?Store $store = null, ?Integration $integration = null): array
    {
        $user = User::query()->create([
            'name' => 'Incident Tester',
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

        return compact('user', 'tenant', 'store', 'integration');
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
