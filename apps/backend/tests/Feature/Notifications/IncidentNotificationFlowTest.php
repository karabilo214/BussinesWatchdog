<?php

namespace Tests\Feature\Notifications;

use App\Models\DomainOutbox;
use App\Models\FinancialTransaction;
use App\Models\Incident;
use App\Models\Integration;
use App\Models\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Support\Incidents\IncidentLifecycleService;
use App\Support\Incidents\MoneyIncidentCorrelator;
use App\Support\Notifications\Channels\NotificationSenderRegistry;
use App\Support\Notifications\Channels\SendResult;
use App\Support\Notifications\NotificationDeliveryWorker;
use App\Support\Outbox\DomainOutboxDispatcher;
use App\Support\Payments\PaymentAllocationService;
use App\Support\Reconciliation\OrderReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IncidentNotificationFlowTest extends TestCase
{
    use NotificationTestContext;
    use RefreshDatabase;

    private FakeNotificationSender $sender;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sender = new FakeNotificationSender;
        $this->app->instance(NotificationSenderRegistry::class, new NotificationSenderRegistry([$this->sender]));
    }

    public function test_opening_an_incident_requests_a_notification_in_the_same_transaction(): void
    {
        $context = $this->context();
        $order = $this->order($context, ['paid_marked_at' => now()->subMinutes(31)]);

        [$incident] = $this->evaluate($order);

        $this->assertDatabaseHas('domain_outbox', [
            'tenant_id' => $context['tenant']->id,
            'topic' => DomainOutbox::TOPIC_INCIDENT_NOTIFICATION_REQUESTED,
            'dedupe_key' => 'incident:'.$incident->id.':rev:1:'.NotificationDelivery::KIND_INCIDENT_OPENED,
        ]);
    }

    public function test_a_rolled_back_transition_leaves_no_notification_request(): void
    {
        $context = $this->context();
        $order = $this->order($context, ['paid_marked_at' => now()->subMinutes(31)]);
        $run = app(OrderReconciliationService::class)->evaluate($order);

        DB::beginTransaction();
        app(MoneyIncidentCorrelator::class)->correlate($run);
        DB::rollBack();

        $this->assertSame(0, Incident::query()->count());
        $this->assertSame(0, DomainOutbox::query()->where('topic', DomainOutbox::TOPIC_INCIDENT_NOTIFICATION_REQUESTED)->count());
    }

    public function test_dispatch_fans_out_only_to_enabled_verified_channels_and_replay_is_a_no_op(): void
    {
        $context = $this->context();
        $deliverable = $this->verifiedEmailChannel($context['tenant']);
        $this->verifiedEmailChannel($context['tenant'], 'disabled@example.test', [], false);
        $unverified = $this->verifiedEmailChannel($context['tenant'], 'unverified@example.test');
        $unverified->forceFill(['verified_at' => null])->save();
        $foreign = $this->context();
        $this->verifiedEmailChannel($foreign['tenant'], 'foreign@example.test');

        [$incident] = $this->evaluate($this->order($context, ['paid_marked_at' => now()->subMinutes(31)]));
        $this->dispatchOutbox();
        $this->dispatchOutbox();

        $deliveries = NotificationDelivery::query()->get();
        $this->assertCount(1, $deliveries);
        $this->assertSame($deliverable->id, $deliveries[0]->channel_id);
        $this->assertSame($incident->id, $deliveries[0]->incident_id);
        $this->assertSame(NotificationDelivery::STATUS_QUEUED, $deliveries[0]->status);
        $this->assertSame(NotificationDelivery::KIND_INCIDENT_OPENED, $deliveries[0]->notification_kind);
    }

    public function test_a_repeated_mismatch_without_a_transition_does_not_notify_again(): void
    {
        $context = $this->context();
        $this->verifiedEmailChannel($context['tenant']);
        $order = $this->order($context, ['paid_marked_at' => now()->subMinutes(31)]);

        $this->evaluate($order);
        $this->evaluate($order->fresh());
        $this->dispatchOutbox();

        $this->assertSame(1, NotificationDelivery::query()->count());
    }

    public function test_worker_sends_a_sanitized_localized_message_with_known_amount(): void
    {
        $context = $this->context();
        $this->verifiedEmailChannel($context['tenant'], 'alerts@example.test', ['locale' => 'ru']);

        [$incident] = $this->evaluate($this->order($context, ['paid_marked_at' => now()->subMinutes(31)]));
        $this->dispatchOutbox();
        $result = app(NotificationDeliveryWorker::class)->deliverDue();

        $this->assertSame(1, $result['sent']);
        $this->assertCount(1, $this->sender->sent);
        $sent = $this->sender->sent[0];
        $this->assertSame('alerts@example.test', $sent['destination']);
        $this->assertStringContainsString('Main Shop', $sent['message']->subject);
        $this->assertStringContainsString('#15238', $sent['message']->body);
        $this->assertStringContainsString('184.00 EUR', $sent['message']->body);
        $this->assertStringContainsString('не позднее', $sent['message']->body);
        $this->assertStringContainsString('/app/incidents/'.$incident->id, $sent['message']->body);
        $this->assertStringNotContainsString($context['tenant']->id, $sent['message']->body);

        $delivery = NotificationDelivery::query()->firstOrFail();
        $this->assertSame(NotificationDelivery::STATUS_SENT, $delivery->status);
        $this->assertNotNull($delivery->sent_at);
        $this->assertSame('ok', NotificationChannel::query()->firstOrFail()->health['status']);
    }

    public function test_auto_resolution_sends_a_recovery_notification_unless_opted_out(): void
    {
        $context = $this->context();
        $this->verifiedEmailChannel($context['tenant']);
        $this->verifiedEmailChannel($context['tenant'], 'no-recovery@example.test', ['notify_recovery' => false]);
        $order = $this->order($context, ['paid_marked_at' => now()->subMinutes(31)]);

        $this->evaluate($order);
        $this->allocateFullCapture($context, $order);
        [$resolved] = $this->evaluate($order->fresh());
        $this->dispatchOutbox();

        $this->assertSame(Incident::STATE_RESOLVED, $resolved->state);
        $this->assertSame(2, NotificationDelivery::query()->where('notification_kind', NotificationDelivery::KIND_INCIDENT_OPENED)->count());
        $this->assertSame(1, NotificationDelivery::query()->where('notification_kind', NotificationDelivery::KIND_INCIDENT_RECOVERED)->count());

        app(NotificationDeliveryWorker::class)->deliverDue();
        $recovery = collect($this->sender->sent)->first(
            fn (array $item): bool => str_contains($item['message']->body, 'Период расхождения'),
        );
        $this->assertNotNull($recovery);
    }

    public function test_reopen_within_window_notifies_with_the_reopened_kind(): void
    {
        $context = $this->context();
        $this->verifiedEmailChannel($context['tenant']);
        $order = $this->order($context, ['paid_marked_at' => now()->subMinutes(31)]);

        [$incident] = $this->evaluate($order);
        app(IncidentLifecycleService::class)->resolve($incident, 'Manual check done.', null);
        $this->evaluate($order->fresh());
        $this->dispatchOutbox();

        $this->assertSame(
            [NotificationDelivery::KIND_INCIDENT_OPENED, NotificationDelivery::KIND_INCIDENT_REOPENED],
            NotificationDelivery::query()->orderBy('incident_revision')->pluck('notification_kind')->all(),
        );
    }

    public function test_severity_threshold_and_store_filter_skip_channels(): void
    {
        $context = $this->context();
        $this->verifiedEmailChannel($context['tenant'], 'critical-only@example.test', ['min_severity' => 'critical']);
        $otherStore = $this->context('owner', $context['tenant'])['store'];
        $this->verifiedEmailChannel($context['tenant'], 'other-store@example.test', ['store_ids' => [$otherStore->id]]);

        $this->evaluate($this->order($context, ['paid_marked_at' => now()->subMinutes(31)]));
        $this->dispatchOutbox();

        $this->assertSame(0, NotificationDelivery::query()->count());
    }

    public function test_an_active_suppression_records_a_suppressed_delivery_that_is_never_sent(): void
    {
        $context = $this->context();
        $this->verifiedEmailChannel($context['tenant']);
        $order = $this->order($context, ['paid_marked_at' => now()->subMinutes(31)]);

        [$incident] = $this->evaluate($order);
        app(IncidentLifecycleService::class)->snooze($incident, now()->addDay(), 'Known issue in progress.', $context['user']->id);
        $this->dispatchOutbox();
        app(NotificationDeliveryWorker::class)->deliverDue();

        $delivery = NotificationDelivery::query()->firstOrFail();
        $this->assertSame(NotificationDelivery::STATUS_SUPPRESSED, $delivery->status);
        $this->assertSame('incident_suppressed', $delivery->error_code);
        $this->assertCount(0, $this->sender->sent);
    }

    public function test_quiet_hours_defer_delivery_until_the_window_ends(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-09 23:30:00', 'Europe/Kyiv'));

        $context = $this->context();
        $this->verifiedEmailChannel($context['tenant'], 'alerts@example.test', [
            'timezone' => 'Europe/Kyiv',
            'quiet_hours' => ['start' => '22:00', 'end' => '08:00'],
        ]);

        $this->evaluate($this->order($context, ['paid_marked_at' => now()->subMinutes(31)]));
        $this->dispatchOutbox();

        $delivery = NotificationDelivery::query()->firstOrFail();
        $this->assertTrue($delivery->next_attempt_at->equalTo(Carbon::parse('2026-10-10 08:00:00', 'Europe/Kyiv')));

        app(NotificationDeliveryWorker::class)->deliverDue();
        $this->assertCount(0, $this->sender->sent);

        Carbon::setTestNow(Carbon::parse('2026-10-10 08:00:01', 'Europe/Kyiv'));
        app(NotificationDeliveryWorker::class)->deliverDue();
        $this->assertCount(1, $this->sender->sent);

        Carbon::setTestNow();
    }

    public function test_transient_failures_follow_the_retry_schedule_and_respect_retry_after(): void
    {
        $context = $this->context();
        $this->verifiedEmailChannel($context['tenant']);
        $this->evaluate($this->order($context, ['paid_marked_at' => now()->subMinutes(31)]));
        $this->dispatchOutbox();
        $worker = app(NotificationDeliveryWorker::class);

        $this->sender->queuedResults = [
            SendResult::transientFailure('smtp_unavailable'),
            SendResult::transientFailure('provider_rate_limited', 1200),
        ];

        $start = Carbon::now();
        $worker->deliverDue();
        $delivery = NotificationDelivery::query()->firstOrFail();
        $this->assertSame(NotificationDelivery::STATUS_FAILED, $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertEqualsWithDelta(60, $start->diffInSeconds($delivery->next_attempt_at), 2);

        Carbon::setTestNow($delivery->next_attempt_at->copy()->addSecond());
        $worker->deliverDue();
        $delivery->refresh();
        $this->assertSame(2, $delivery->attempts);
        $this->assertSame('provider_rate_limited', $delivery->error_code);
        $this->assertEqualsWithDelta(1200, Carbon::now()->diffInSeconds($delivery->next_attempt_at), 2);

        Carbon::setTestNow($delivery->next_attempt_at->copy()->addSecond());
        $worker->deliverDue();
        $delivery->refresh();
        $this->assertSame(NotificationDelivery::STATUS_SENT, $delivery->status);
        $this->assertSame(1, NotificationDelivery::query()->count());

        Carbon::setTestNow();
    }

    public function test_failures_past_24_hours_become_dead_letter_and_mark_channel_health(): void
    {
        $context = $this->context();
        $channel = $this->verifiedEmailChannel($context['tenant']);
        $this->evaluate($this->order($context, ['paid_marked_at' => now()->subMinutes(31)]));
        $this->dispatchOutbox();
        $delivery = NotificationDelivery::query()->firstOrFail();
        $delivery->forceFill(['created_at' => now()->subHours(23)])->save();

        $this->sender->queuedResults = [SendResult::transientFailure('smtp_unavailable')];
        $delivery->forceFill(['attempts' => 5])->save();
        app(NotificationDeliveryWorker::class)->deliverDue();

        $delivery->refresh();
        $this->assertSame(NotificationDelivery::STATUS_DEAD_LETTER, $delivery->status);
        $this->assertSame('smtp_unavailable', $delivery->error_code);
        $health = $channel->fresh()->health;
        $this->assertSame('failing', $health['status']);
        $this->assertSame('smtp_unavailable', $health['last_error_code']);
    }

    public function test_timeout_is_recorded_as_uncertain_and_not_retried(): void
    {
        $context = $this->context();
        $this->verifiedEmailChannel($context['tenant']);
        $this->evaluate($this->order($context, ['paid_marked_at' => now()->subMinutes(31)]));
        $this->dispatchOutbox();

        $this->sender->queuedResults = [SendResult::uncertain('email_transport_timeout')];
        $worker = app(NotificationDeliveryWorker::class);
        $worker->deliverDue();

        Carbon::setTestNow(now()->addDay());
        $worker->deliverDue();
        Carbon::setTestNow();

        $delivery = NotificationDelivery::query()->firstOrFail();
        $this->assertSame(NotificationDelivery::STATUS_UNCERTAIN, $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertCount(1, $this->sender->sent);
    }

    public function test_a_delivery_stuck_in_sending_past_its_lease_is_swept_to_uncertain(): void
    {
        $context = $this->context();
        $this->verifiedEmailChannel($context['tenant']);
        $this->evaluate($this->order($context, ['paid_marked_at' => now()->subMinutes(31)]));
        $this->dispatchOutbox();
        NotificationDelivery::query()->update([
            'status' => NotificationDelivery::STATUS_SENDING,
            'attempts' => 1,
            'next_attempt_at' => now()->subMinute(),
        ]);

        $result = app(NotificationDeliveryWorker::class)->deliverDue();

        $this->assertSame(1, $result['swept']);
        $delivery = NotificationDelivery::query()->firstOrFail();
        $this->assertSame(NotificationDelivery::STATUS_UNCERTAIN, $delivery->status);
        $this->assertSame('worker_lost_during_send', $delivery->error_code);
        $this->assertCount(0, $this->sender->sent);
    }

    public function test_a_channel_disabled_after_planning_suppresses_the_delivery(): void
    {
        $context = $this->context();
        $channel = $this->verifiedEmailChannel($context['tenant']);
        $this->evaluate($this->order($context, ['paid_marked_at' => now()->subMinutes(31)]));
        $this->dispatchOutbox();
        $channel->forceFill(['enabled' => false])->save();

        app(NotificationDeliveryWorker::class)->deliverDue();

        $this->assertSame(NotificationDelivery::STATUS_SUPPRESSED, NotificationDelivery::query()->firstOrFail()->status);
        $this->assertCount(0, $this->sender->sent);
    }

    /**
     * @return list<Incident>
     */
    private function evaluate(Order $order): array
    {
        $run = app(OrderReconciliationService::class)->evaluate($order);

        return app(MoneyIncidentCorrelator::class)->correlate($run);
    }

    private function dispatchOutbox(): void
    {
        app(DomainOutboxDispatcher::class)->dispatchDue();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function allocateFullCapture(array $context, Order $order): void
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
            'source_authority' => Integration::SOURCE_INDEPENDENT_PROVIDER,
            'source_updated_at' => now()->subMinute(),
            'current_payload_hash' => hash('sha256', fake()->uuid()),
            'metadata' => [],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $capture = FinancialTransaction::query()->create([
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
            'occurred_at' => now()->subMinute(),
            'source_authority' => Integration::SOURCE_INDEPENDENT_PROVIDER,
            'operation_hash' => hash('sha256', fake()->uuid()),
            'metadata' => [],
            'created_at' => now(),
        ]);

        app(PaymentAllocationService::class)->allocateCapture(
            $payment,
            $capture,
            $order,
            18400,
            PaymentAllocation::STRATEGY_EXACT_REFERENCE,
            [],
        );
    }
}
