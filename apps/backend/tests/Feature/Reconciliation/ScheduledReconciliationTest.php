<?php

namespace Tests\Feature\Reconciliation;

use App\Models\DomainOutbox;
use App\Models\FinancialTransaction;
use App\Models\Incident;
use App\Models\Integration;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\ReconciliationDirtySubject;
use App\Models\ReconciliationRun;
use App\Models\ScheduledJobWindow;
use App\Support\Incidents\MoneyIncidentCorrelator;
use App\Support\Integrations\ConnectorFreshness;
use App\Support\Integrations\ProviderCoverage;
use App\Support\Notifications\IncidentNotificationRequester;
use App\Support\Payments\PaymentAllocationService;
use App\Support\Reconciliation\DirtySubjectMarker;
use App\Support\Reconciliation\DirtySubjectProcessor;
use App\Support\Reconciliation\NightlyReconciliationSweep;
use App\Support\Reconciliation\OrderReconciliationService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Feature\Notifications\NotificationTestContext;
use Tests\TestCase;

class ScheduledReconciliationTest extends TestCase
{
    use NotificationTestContext;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_repeated_marks_coalesce_into_one_subject_without_pushing_the_due_time_later(): void
    {
        $context = $this->context();
        $order = $this->order($context);
        $marker = app(DirtySubjectMarker::class);

        $marker->markOrder($context['tenant']->id, $context['store']->id, $order->id, ReconciliationDirtySubject::REASON_ORDER_EVENT);
        $firstDue = ReconciliationDirtySubject::query()->firstOrFail()->due_at;

        Carbon::setTestNow(now()->addSeconds(20));
        $marker->markOrder($context['tenant']->id, $context['store']->id, $order->id, ReconciliationDirtySubject::REASON_REFUND_EVENT);

        $subjects = ReconciliationDirtySubject::query()->get();
        $this->assertCount(1, $subjects);
        $this->assertSame(2, $subjects[0]->mark_version);
        $this->assertTrue($subjects[0]->due_at->equalTo($firstDue));
    }

    public function test_due_dirty_order_is_reconciled_correlated_and_cleared(): void
    {
        $context = $this->context();
        $order = $this->order($context, ['paid_marked_at' => now()->subHour()]);
        app(DirtySubjectMarker::class)->markOrder($context['tenant']->id, $context['store']->id, $order->id, ReconciliationDirtySubject::REASON_ORDER_EVENT);

        $notDueYet = app(DirtySubjectProcessor::class)->processDue();
        $this->assertSame(0, $notDueYet['claimed']);

        Carbon::setTestNow(now()->addSeconds(31));
        $result = app(DirtySubjectProcessor::class)->processDue();

        $this->assertSame(['claimed' => 1, 'processed' => 1, 'skipped' => 0, 'failed' => 0], $result);
        $this->assertSame(0, ReconciliationDirtySubject::query()->count());
        $run = ReconciliationRun::query()->firstOrFail();
        $this->assertSame(DirtySubjectProcessor::TRIGGER, $run->scope['trigger']);
        $this->assertSame(Incident::STATE_OPEN, Incident::query()->firstOrFail()->state);
        $this->assertSame(1, DomainOutbox::query()->where('topic', DomainOutbox::TOPIC_INCIDENT_NOTIFICATION_REQUESTED)->count());
    }

    public function test_order_within_grace_is_rechecked_at_the_grace_deadline(): void
    {
        $context = $this->context();
        $order = $this->order($context, ['paid_marked_at' => now()->subMinutes(10)]);
        app(DirtySubjectMarker::class)->markOrder($context['tenant']->id, $context['store']->id, $order->id, ReconciliationDirtySubject::REASON_ORDER_EVENT, now());

        app(DirtySubjectProcessor::class)->processDue();

        $this->assertSame(0, Incident::query()->count());
        $recheck = ReconciliationDirtySubject::query()->firstOrFail();
        $this->assertSame(ReconciliationDirtySubject::REASON_GRACE_DEADLINE, $recheck->reason);
        $this->assertTrue($recheck->due_at->equalTo($order->fresh()->paid_marked_at->copy()->addMinutes(OrderReconciliationService::GRACE_CAPTURE_MINUTES)));

        Carbon::setTestNow($recheck->due_at->copy()->addSecond());
        app(DirtySubjectProcessor::class)->processDue();

        $this->assertSame(Incident::STATE_OPEN, Incident::query()->firstOrFail()->state);
        $this->assertSame(0, ReconciliationDirtySubject::query()->count());
    }

    public function test_api_trigger_also_schedules_the_grace_recheck(): void
    {
        $context = $this->context('admin');
        $order = $this->order($context, ['paid_marked_at' => now()->subMinutes(5)]);

        $this->actingAs($context['user'])
            ->withSession(['active_tenant_id' => $context['tenant']->id])
            ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/v1/stores/{$context['store']->id}/reconciliations", ['order_ids' => [$order->id]])
            ->assertStatus(202);

        $this->assertDatabaseHas('reconciliation_dirty_subjects', [
            'subject_id' => $order->id,
            'reason' => ReconciliationDirtySubject::REASON_GRACE_DEADLINE,
        ]);
    }

    public function test_a_mark_arriving_during_processing_keeps_the_subject_for_another_pass(): void
    {
        $context = $this->context();
        $order = $this->order($context, ['paid_marked_at' => now()->subHour()]);
        $marker = app(DirtySubjectMarker::class);
        $marker->markOrder($context['tenant']->id, $context['store']->id, $order->id, ReconciliationDirtySubject::REASON_ORDER_EVENT, now());

        $this->app->bind(MoneyIncidentCorrelator::class, fn ($app) => new class($app->make(IncidentNotificationRequester::class), $marker, $context, $order) extends MoneyIncidentCorrelator
        {
            public function __construct($requester, private $marker, private $context, private $order)
            {
                parent::__construct($requester);
            }

            public function correlate(ReconciliationRun $run): array
            {
                $this->marker->markOrder($this->context['tenant']->id, $this->context['store']->id, $this->order->id, ReconciliationDirtySubject::REASON_PAYMENT_EVENT);

                return parent::correlate($run);
            }
        });

        app(DirtySubjectProcessor::class)->processDue();

        $subject = ReconciliationDirtySubject::query()->firstOrFail();
        $this->assertSame(2, $subject->mark_version);
        $this->assertNull($subject->lease_until);
        $this->assertTrue($subject->due_at->lessThanOrEqualTo(now()));
    }

    public function test_a_failing_subject_is_released_with_backoff_and_an_error_code(): void
    {
        $context = $this->context();
        $order = $this->order($context);
        app(DirtySubjectMarker::class)->markOrder($context['tenant']->id, $context['store']->id, $order->id, ReconciliationDirtySubject::REASON_ORDER_EVENT, now());
        $this->app->bind(OrderReconciliationService::class, fn () => new class(new ProviderCoverage, app(ConnectorFreshness::class)) extends OrderReconciliationService
        {
            public function evaluate(Order $order, string $trigger = 'manual'): ReconciliationRun
            {
                throw new RuntimeException('boom');
            }
        });

        $result = app(DirtySubjectProcessor::class)->processDue();

        $this->assertSame(1, $result['failed']);
        $subject = ReconciliationDirtySubject::query()->firstOrFail();
        $this->assertSame(DirtySubjectProcessor::ERROR_PROCESSING_FAILED, $subject->error_code);
        $this->assertNull($subject->lease_until);
        $this->assertSame(1, $subject->attempts);
        $this->assertEqualsWithDelta(60, now()->diffInSeconds($subject->due_at), 2);
    }

    public function test_a_subject_for_a_missing_order_is_skipped_and_cleared(): void
    {
        $context = $this->context();
        app(DirtySubjectMarker::class)->markOrder($context['tenant']->id, $context['store']->id, (string) Str::uuid(), ReconciliationDirtySubject::REASON_ORDER_EVENT, now());

        $result = app(DirtySubjectProcessor::class)->processDue();

        $this->assertSame(1, $result['skipped']);
        $this->assertSame(0, ReconciliationDirtySubject::query()->count());
    }

    public function test_an_active_lease_is_not_reclaimed_until_it_expires(): void
    {
        $context = $this->context();
        $order = $this->order($context);
        app(DirtySubjectMarker::class)->markOrder($context['tenant']->id, $context['store']->id, $order->id, ReconciliationDirtySubject::REASON_ORDER_EVENT, now());
        ReconciliationDirtySubject::query()->update(['lease_until' => now()->addMinute()]);

        $this->assertSame(0, app(DirtySubjectProcessor::class)->processDue()['claimed']);

        Carbon::setTestNow(now()->addMinutes(2));
        $this->assertSame(1, app(DirtySubjectProcessor::class)->processDue()['claimed']);
    }

    public function test_allocating_a_capture_marks_the_order_and_store_scan_dirty(): void
    {
        $context = $this->context();
        $order = $this->order($context, ['paid_marked_at' => now()->subHour()]);
        [$payment, $capture] = $this->paymentWithCapture($context);

        app(PaymentAllocationService::class)->allocateCapture($payment, $capture, $order, 18400, PaymentAllocation::STRATEGY_EXACT_REFERENCE, []);

        $this->assertDatabaseHas('reconciliation_dirty_subjects', [
            'subject_type' => ReconciliationDirtySubject::TYPE_ORDER,
            'subject_id' => $order->id,
            'reason' => ReconciliationDirtySubject::REASON_ALLOCATION_CHANGED,
        ]);
        $this->assertDatabaseHas('reconciliation_dirty_subjects', [
            'subject_type' => ReconciliationDirtySubject::TYPE_STORE_UNMATCHED_PAYMENTS,
            'subject_id' => $context['store']->id,
        ]);
    }

    public function test_unmatched_capture_scan_is_rechecked_when_its_grace_ends(): void
    {
        $context = $this->context();
        [, $capture] = $this->paymentWithCapture($context, now()->subHours(2));
        app(DirtySubjectMarker::class)->markStoreUnmatchedPayments($context['tenant']->id, $context['store']->id, ReconciliationDirtySubject::REASON_PAYMENT_EVENT, now());

        app(DirtySubjectProcessor::class)->processDue();

        $recheck = ReconciliationDirtySubject::query()->firstOrFail();
        $this->assertSame(ReconciliationDirtySubject::TYPE_STORE_UNMATCHED_PAYMENTS, $recheck->subject_type);
        $this->assertSame(ReconciliationDirtySubject::REASON_GRACE_DEADLINE, $recheck->reason);
        $this->assertTrue($recheck->due_at->equalTo($capture->occurred_at->copy()->addHours(24)));
    }

    public function test_nightly_sweep_marks_recent_orders_of_active_stores_once_per_day(): void
    {
        Carbon::setTestNow('2026-10-09 02:30:00');
        $context = $this->context();
        $recent = $this->order($context, ['source_created_at' => now()->subDays(10), 'source_updated_at' => now()->subDays(5)]);
        $this->order($context, ['source_created_at' => now()->subDays(200), 'source_updated_at' => now()->subDays(120)]);
        $paused = $this->context();
        $paused['store']->forceFill(['status' => 'paused'])->save();
        $this->order($paused, ['source_created_at' => now()->subDay(), 'source_updated_at' => now()->subDay()]);
        app(DirtySubjectMarker::class)->markOrder($context['tenant']->id, $context['store']->id, $recent->id, ReconciliationDirtySubject::REASON_ORDER_EVENT);

        $first = app(NightlyReconciliationSweep::class)->run();
        $second = app(NightlyReconciliationSweep::class)->run();

        $this->assertSame(['stores' => 1, 'orders_marked' => 0], $first);
        $this->assertNull($second);
        $this->assertSame(1, ReconciliationDirtySubject::query()->where('subject_type', ReconciliationDirtySubject::TYPE_ORDER)->count());
        $this->assertSame(1, ReconciliationDirtySubject::query()->where('subject_type', ReconciliationDirtySubject::TYPE_STORE_UNMATCHED_PAYMENTS)->count());
        $window = ScheduledJobWindow::query()->firstOrFail();
        $this->assertSame('2026-10-09', $window->window_key);
        $this->assertSame(ScheduledJobWindow::STATUS_COMPLETED, $window->status);

        ReconciliationDirtySubject::query()->delete();
        Carbon::setTestNow('2026-10-10 02:30:00');
        $this->assertSame(['stores' => 1, 'orders_marked' => 1], app(NightlyReconciliationSweep::class)->run());
    }

    public function test_a_failed_or_stale_sweep_window_can_be_retried(): void
    {
        Carbon::setTestNow('2026-10-09 02:30:00');
        ScheduledJobWindow::query()->create([
            'job' => NightlyReconciliationSweep::JOB,
            'window_key' => '2026-10-09',
            'status' => ScheduledJobWindow::STATUS_FAILED,
            'started_at' => now()->subMinutes(5),
        ]);

        $this->assertNotNull(app(NightlyReconciliationSweep::class)->run());

        ScheduledJobWindow::query()->update(['status' => ScheduledJobWindow::STATUS_RUNNING, 'started_at' => now()->subMinutes(10)]);
        $this->assertNull(app(NightlyReconciliationSweep::class)->run());

        ScheduledJobWindow::query()->update(['started_at' => now()->subHours(3)]);
        $this->assertNotNull(app(NightlyReconciliationSweep::class)->run());
    }

    public function test_background_commands_are_registered_on_the_scheduler(): void
    {
        $commands = collect(app(Schedule::class)->events())
            ->map(fn ($event): string => (string) $event->command)
            ->implode("\n");

        foreach (['outbox:dispatch', 'reconciliation:process-dirty', 'notifications:deliver', 'reconciliation:nightly-sweep'] as $command) {
            $this->assertStringContainsString($command, $commands);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{Payment, FinancialTransaction}
     */
    private function paymentWithCapture(array $context, ?Carbon $occurredAt = null): array
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
            'occurred_at' => $occurredAt ?? now()->subMinute(),
            'source_authority' => Integration::SOURCE_INDEPENDENT_PROVIDER,
            'operation_hash' => hash('sha256', fake()->uuid()),
            'metadata' => [],
            'created_at' => now(),
        ]);

        return [$payment, $capture];
    }
}
