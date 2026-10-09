<?php

namespace Tests\Feature\Checkout;

use App\Models\DomainOutbox;
use App\Models\EventInbox;
use App\Models\Incident;
use App\Models\Integration;
use App\Models\NotificationDelivery;
use App\Models\PaymentAttemptWindow;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Checkout\PaymentAttemptMonitor;
use App\Support\Ingest\EventInboxProcessor;
use App\Support\Ingest\EventPayloadValidator;
use App\Support\Notifications\Channels\NotificationSenderRegistry;
use App\Support\Notifications\NotificationDeliveryWorker;
use App\Support\Outbox\DomainOutboxDispatcher;
use App\Support\Projections\PaymentAttemptsProjector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Notifications\FakeNotificationSender;
use Tests\Feature\Notifications\NotificationTestContext;
use Tests\TestCase;

class PaymentAttemptMonitoringTest extends TestCase
{
    use NotificationTestContext;
    use RefreshDatabase;

    private Carbon $window;

    protected function setUp(): void
    {
        parent::setUp();

        $this->window = Carbon::parse('2026-10-09 10:00:00', 'UTC');
        Carbon::setTestNow($this->window->copy()->addHour());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_window_is_projected_per_payment_method(): void
    {
        $context = $this->connectorContext();

        $this->assertTrue($this->project($context, 0, [
            $this->method('stripe', paid: 2, failed: 1, trailing: 1, classes: ['gateway_error' => 1]),
            $this->method('bacs', onHold: 1, rejected: 2),
        ]));

        $stripe = PaymentAttemptWindow::query()->where('payment_method', 'stripe')->sole();
        $this->assertSame(2, $stripe->paid);
        $this->assertSame(1, $stripe->trailing_failures);
        $this->assertSame(['gateway_error' => 1], $stripe->failure_classes);
        $this->assertTrue($stripe->window_start->equalTo($this->window));
        $this->assertSame(2, PaymentAttemptWindow::query()->where('payment_method', 'bacs')->sole()->rejected_before_order);
        $this->assertSame(0, Incident::query()->count());
    }

    public function test_three_consecutive_failures_across_windows_open_one_incident_and_notify_once(): void
    {
        $context = $this->connectorContext();

        $this->project($context, 0, [$this->method('stripe', paid: 1, failed: 1, trailing: 1)]);
        $this->project($context, 1, [$this->method('stripe', pendingStuck: 1)]);
        $this->assertSame(0, Incident::query()->count());

        $this->project($context, 2, [$this->method('stripe', failed: 1, classes: ['gateway_error' => 1])]);
        $this->project($context, 3, [$this->method('stripe', failed: 2, classes: ['status_failed' => 2])]);

        $incident = Incident::query()->sole();
        $this->assertSame(PaymentAttemptMonitor::FAMILY, $incident->family);
        $this->assertSame(PaymentAttemptMonitor::RULE_CODE, $incident->title_code);
        $this->assertSame(Incident::STATE_OPEN, $incident->state);
        $this->assertNull($incident->currency);
        $this->assertTrue($incident->last_good_at->equalTo($this->window->copy()->addMinutes(5)));
        $this->assertSame(1, DomainOutbox::query()->where('topic', DomainOutbox::TOPIC_INCIDENT_NOTIFICATION_REQUESTED)->count());

        $state = app(PaymentAttemptMonitor::class)->state($context['tenant']->id, $context['store']->id, 'stripe');
        $this->assertSame(5, $state['streak']);
        $this->assertSame(['gateway_error' => 1, 'status_failed' => 2], $state['failure_classes']);
    }

    public function test_a_successful_payment_resolves_the_incident_and_sends_a_recovery(): void
    {
        $context = $this->connectorContext();
        $this->project($context, 0, [$this->method('paypal', failed: 3)]);
        $this->assertSame(Incident::STATE_OPEN, Incident::query()->sole()->state);

        $this->project($context, 1, [$this->method('paypal', failed: 1)]);
        $this->assertSame(Incident::STATE_OPEN, Incident::query()->sole()->state);

        $this->project($context, 2, [$this->method('paypal', failed: 1, lateSuccess: 1, trailing: 1)]);

        $incident = Incident::query()->sole();
        $this->assertSame(Incident::STATE_RESOLVED, $incident->state);
        $this->assertSame('auto_resolved_successful_payment', $incident->resolution_reason);
        $this->assertSame(2, DomainOutbox::query()->where('topic', DomainOutbox::TOPIC_INCIDENT_NOTIFICATION_REQUESTED)->count());
    }

    public function test_on_hold_counts_as_success_and_rejections_before_the_order_do_not_count(): void
    {
        $context = $this->connectorContext();

        $this->project($context, 0, [$this->method('bacs', failed: 2)]);
        $this->project($context, 1, [$this->method('bacs', onHold: 1, rejected: 10)]);
        $this->project($context, 2, [$this->method('bacs', failed: 2, rejected: 5)]);

        $this->assertSame(0, Incident::query()->count());
        $this->assertSame(2, app(PaymentAttemptMonitor::class)->state($context['tenant']->id, $context['store']->id, 'bacs')['streak']);
    }

    public function test_methods_are_independent(): void
    {
        $context = $this->connectorContext();

        $this->project($context, 0, [$this->method('stripe', failed: 2), $this->method('bacs', failed: 2)]);
        $this->project($context, 1, [$this->method('stripe', failed: 1), $this->method('bacs', onHold: 1)]);

        $incident = Incident::query()->sole();
        $this->assertSame(Incident::STATE_OPEN, $incident->state);
    }

    public function test_replays_are_idempotent_and_conflicting_replays_fail(): void
    {
        $context = $this->connectorContext();
        $payload = $this->payload(0, [$this->method('stripe', failed: 3)]);

        $resent = array_merge($payload, ['event_id' => '77777777-7777-4777-8777-777777777777', 'observed_at' => now()->toJSON()]);

        $this->assertTrue($this->processPayload($context, $payload));
        $this->assertTrue($this->processPayload($context, $resent));
        $this->assertSame(1, PaymentAttemptWindow::query()->count());
        $this->assertSame(1, Incident::query()->count());

        $conflicting = $this->payload(0, [$this->method('stripe', failed: 2)]);
        $processor = app(EventInboxProcessor::class);
        $this->assertFalse($processor->processReceived($this->inbox($context, $conflicting)->id));
        $this->assertSame(PaymentAttemptsProjector::ERROR_REVISION_CONFLICT, $processor->lastErrorCode());
        $this->assertSame(3, PaymentAttemptWindow::query()->sole()->failed);
    }

    public function test_the_validator_rejects_inconsistent_or_unsafe_payloads(): void
    {
        $validator = app(EventPayloadValidator::class);

        $this->assertTrue($validator->validate($this->payload(0, [$this->method('stripe', failed: 1, trailing: 1)]))->valid);

        foreach ([
            [$this->method('stripe', failed: 1, trailing: 2)],
            [$this->method('stripe', classes: ['Card declined for jane@example.test' => 1])],
            [$this->method('stripe'), $this->method('stripe')],
            [array_merge($this->method('stripe'), ['customer_email' => 'jane@example.test'])],
        ] as $methods) {
            $this->assertFalse($validator->validate($this->payload(0, $methods))->valid);
        }
    }

    public function test_the_notification_names_the_method_and_streak_without_order_data(): void
    {
        $sender = new FakeNotificationSender;
        $this->app->instance(NotificationSenderRegistry::class, new NotificationSenderRegistry([$sender]));
        $context = $this->connectorContext();
        $this->verifiedEmailChannel($context['tenant'], 'alerts@example.test', ['locale' => 'ru']);

        $this->project($context, 0, [$this->method('stripe', failed: 3)]);
        app(DomainOutboxDispatcher::class)->dispatchDue();
        app(NotificationDeliveryWorker::class)->deliverDue();

        $this->assertCount(1, $sender->sent);
        $message = $sender->sent[0]['message'];
        $this->assertStringContainsString('оплаты не проходят', $message->subject);
        $this->assertStringContainsString('3 попыток оплаты способом «stripe»', $message->body);
        $this->assertStringContainsString('попытки оплаты на сайте магазина', $message->body);
        $this->assertStringNotContainsString('(номер заказа неизвестен)', $message->body);
        $this->assertSame(NotificationDelivery::STATUS_SENT, NotificationDelivery::query()->sole()->status);
    }

    /**
     * @return array{user: User, tenant: Tenant, store: Store, integration: Integration}
     */
    private function connectorContext(): array
    {
        $context = $this->context();
        $context['integration']->forceFill([
            'provider' => 'woocommerce',
            'source_authority' => Integration::SOURCE_STORE_REPORTED,
        ])->save();

        return $context;
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  list<array<string, mixed>>  $methods
     */
    private function project(array $context, int $windowIndex, array $methods): bool
    {
        return $this->processPayload($context, $this->payload($windowIndex, $methods));
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $payload
     */
    private function processPayload(array $context, array $payload): bool
    {
        return app(EventInboxProcessor::class)->processReceived($this->inbox($context, $payload)->id);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $payload
     */
    private function inbox(array $context, array $payload): EventInbox
    {
        return EventInbox::query()->create([
            'tenant_id' => $context['tenant']->id,
            'store_id' => $context['store']->id,
            'integration_id' => $context['integration']->id,
            'provider_event_id' => fake()->uuid(),
            'schema_version' => '1.0',
            'event_type' => $payload['type'],
            'aggregate_type' => $payload['aggregate_type'],
            'aggregate_external_id' => $payload['aggregate_id'],
            'aggregate_revision' => $payload['aggregate_revision'],
            'occurred_at' => now()->subMinute(),
            'observed_at' => now(),
            'received_at' => now(),
            'is_synthetic' => false,
            'payload' => $payload,
            'payload_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
            'canonicalization_version' => 1,
            'status' => EventInbox::STATUS_RECEIVED,
            'attempt_count' => 0,
            'next_attempt_at' => now(),
            'request_id' => fake()->uuid(),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $methods
     * @return array<string, mixed>
     */
    private function payload(int $windowIndex, array $methods): array
    {
        $start = $this->window->copy()->addMinutes(5 * $windowIndex);

        return [
            'schema_version' => '1.0',
            'event_id' => '55555555-5555-4555-8555-'.str_pad((string) $windowIndex, 12, '0', STR_PAD_LEFT),
            'type' => EventInbox::EVENT_CHECKOUT_PAYMENT_ATTEMPTS,
            'aggregate_type' => EventInbox::AGGREGATE_CHECKOUT,
            'aggregate_id' => $start->toJSON(),
            'aggregate_revision' => 1,
            'occurred_at' => $start->copy()->addMinutes(5)->toJSON(),
            'observed_at' => $start->copy()->addMinutes(6)->toJSON(),
            'is_synthetic' => false,
            'data' => [
                'window_start' => $start->toJSON(),
                'window_end' => $start->copy()->addMinutes(5)->toJSON(),
                'methods' => $methods,
            ],
        ];
    }

    /**
     * @param  array<string, int>  $classes
     * @return array<string, mixed>
     */
    private function method(
        string $method,
        int $paid = 0,
        int $onHold = 0,
        int $failed = 0,
        int $pendingStuck = 0,
        int $lateSuccess = 0,
        int $rejected = 0,
        ?int $trailing = null,
        array $classes = [],
    ): array {
        return [
            'payment_method' => $method,
            'paid' => $paid,
            'on_hold' => $onHold,
            'failed' => $failed,
            'pending_stuck' => $pendingStuck,
            'late_success' => $lateSuccess,
            'rejected_before_order' => $rejected,
            'trailing_failures' => $trailing ?? ($paid + $onHold + $lateSuccess > 0 ? 0 : $failed + $pendingStuck),
            'failure_classes' => $classes,
        ];
    }
}
