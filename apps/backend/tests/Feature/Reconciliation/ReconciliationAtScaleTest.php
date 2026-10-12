<?php

namespace Tests\Feature\Reconciliation;

use App\Models\Order;
use App\Models\ReconciliationDirtySubject;
use App\Models\ReconciliationFinding;
use App\Models\ReconciliationRun;
use App\Models\ReconciliationSubjectState;
use App\Support\Reconciliation\DirtySubjectMarker;
use App\Support\Reconciliation\NightlyReconciliationSweep;
use App\Support\Reconciliation\OrderReconciliationService;
use App\Support\Reconciliation\ReconciliationResultRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Notifications\NotificationTestContext;
use Tests\TestCase;

class ReconciliationAtScaleTest extends TestCase
{
    use NotificationTestContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 02:30:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_an_unchanged_result_moves_the_check_time_and_writes_no_new_history(): void
    {
        $context = $this->context();
        $order = $this->order($context, ['paid_marked_at' => now()->subHour()]);
        $service = app(OrderReconciliationService::class);

        $first = $service->evaluate($order);
        $this->assertTrue($first->resultChanged);
        $findings = ReconciliationFinding::query()->count();

        Carbon::setTestNow(now()->addHours(5));
        $again = $service->evaluate($order->fresh());

        $this->assertFalse($again->resultChanged);
        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, ReconciliationRun::query()->count());
        $this->assertSame($findings, ReconciliationFinding::query()->count());
        $state = ReconciliationSubjectState::query()->sole();
        $this->assertSame([$first->id, ReconciliationFinding::STATUS_MISMATCH], [$state->run_id, $state->worst_status]);
        $this->assertTrue($state->evaluated_at->equalTo(now()));
        $this->assertTrue($state->changed_at->equalTo(now()->subHours(5)));

        $order->forceFill(['financial_support' => 'unsupported'])->save();
        $changed = $service->evaluate($order->fresh());

        $this->assertTrue($changed->resultChanged);
        $this->assertSame(2, ReconciliationRun::query()->count());
        $this->assertSame($changed->id, ReconciliationSubjectState::query()->sole()->run_id);
        $this->assertTrue(ReconciliationSubjectState::query()->sole()->changed_at->equalTo(now()));
    }

    public function test_current_findings_follow_the_current_result_even_when_it_has_no_findings(): void
    {
        $context = $this->context('admin');
        $order = $this->order($context, ['paid_marked_at' => now()->subHour()]);
        app(OrderReconciliationService::class)->evaluate($order);
        $current = fn () => $this->actingAs($context['user'])->withSession(['active_tenant_id' => $context['tenant']->id])
            ->getJson("/api/v1/stores/{$context['store']->id}/findings?current=1")->assertOk()->json('data');

        $this->assertNotEmpty($current());

        DB::transaction(fn () => app(ReconciliationResultRecorder::class)->record(ReconciliationResultRecorder::SUBJECT_ORDER, $order->id, [
            'tenant_id' => $order->tenant_id,
            'store_id' => $order->store_id,
            'algorithm_version' => OrderReconciliationService::ALGORITHM_VERSION,
            'config_version' => OrderReconciliationService::CONFIG_VERSION,
            'currency' => 'EUR',
            'scope' => ['order_id' => $order->id, 'trigger' => 'manual'],
            'coverage_snapshot' => [],
            'counters' => [],
            'started_at' => now(),
            'created_at' => now(),
        ], []));

        $this->assertSame([], $current());
        $this->assertSame(0, ReconciliationSubjectState::query()->sole()->finding_count);
        $this->assertNull(ReconciliationSubjectState::query()->sole()->worst_status);
    }

    public function test_nightly_sweep_queues_only_what_can_change_plus_the_oldest_thirtieth(): void
    {
        $context = $this->context();
        $service = app(OrderReconciliationService::class);
        $make = fn (array $overrides = []): Order => $this->order($context, array_merge(['paid_marked_at' => now()->subHour(), 'source_created_at' => now()->subDays(20), 'source_updated_at' => now()->subDays(20), 'updated_at' => now()->subDays(20)], $overrides));

        $never = $make();
        $pending = $make(['paid_marked_at' => now()->subMinutes(5)]);
        $settled = $make();
        $changedSince = $make();
        $oldRules = $make();
        $planChanged = $make();
        $monthOld = $make(['updated_at' => now()->subDays(50)]);
        $outside = $make(['source_created_at' => now()->subDays(120), 'source_updated_at' => now()->subDays(100)]);

        foreach ([$pending, $settled, $changedSince, $oldRules, $planChanged, $monthOld, $outside] as $order) {
            $service->evaluate($order);
        }

        $this->assertSame(ReconciliationFinding::STATUS_PENDING, $this->state($pending)->worst_status);
        Order::query()->whereKey($changedSince->id)->update(['updated_at' => now()->addMinute()]);
        ReconciliationSubjectState::query()->where('subject_id', $oldRules->id)->update(['algorithm_version' => 'v0']);
        ReconciliationSubjectState::query()->where('subject_id', $planChanged->id)->update(['entitled' => false]);
        ReconciliationSubjectState::query()->whereIn('subject_id', [$monthOld->id, $outside->id])->update(['evaluated_at' => now()->subDays(40)]);
        ReconciliationDirtySubject::query()->delete();

        $result = app(NightlyReconciliationSweep::class)->run();

        $this->assertSame(['stores' => 1, 'orders_marked' => 5, 'orders_rechecked' => 1], $result);
        $marked = ReconciliationDirtySubject::query()->where('subject_type', ReconciliationDirtySubject::TYPE_ORDER)->pluck('subject_id')->sort()->values()->all();
        $expected = collect([$never, $pending, $changedSince, $oldRules, $planChanged, $monthOld])->pluck('id')->sort()->values()->all();
        $this->assertSame($expected, $marked);
        $this->assertSame(1, ReconciliationDirtySubject::query()->where('subject_type', ReconciliationDirtySubject::TYPE_STORE_UNMATCHED_PAYMENTS)->count());
    }

    public function test_the_monthly_recheck_takes_the_longest_unchecked_orders_within_a_nightly_budget(): void
    {
        $context = $this->context();
        $service = app(OrderReconciliationService::class);
        $orders = collect(range(1, NightlyReconciliationSweep::MIN_RECHECK_PER_STORE + 20))->map(fn (int $day): Order => $this->order($context, ['paid_marked_at' => now()->subHour(), 'source_created_at' => now()->subDays(60), 'source_updated_at' => now()->subDays(60), 'updated_at' => now()->subDays(200)]));

        foreach ($orders as $index => $order) {
            $service->evaluate($order);
            ReconciliationSubjectState::query()->where('subject_id', $order->id)->update(['evaluated_at' => now()->subDays(31 + $index)]);
        }

        ReconciliationDirtySubject::query()->delete();
        $result = app(NightlyReconciliationSweep::class)->run();

        $this->assertSame(NightlyReconciliationSweep::MIN_RECHECK_PER_STORE, $result['orders_rechecked']);
        $marked = ReconciliationDirtySubject::query()->where('subject_type', ReconciliationDirtySubject::TYPE_ORDER)->pluck('subject_id');
        $this->assertFalse($marked->contains($orders->first()->id));
        $this->assertTrue($marked->contains($orders->last()->id));
    }

    public function test_workers_process_until_empty_and_a_second_pass_writes_nothing(): void
    {
        $context = $this->context();
        $marker = app(DirtySubjectMarker::class);
        $orders = collect(range(1, 7))->map(fn () => $this->order($context, ['paid_marked_at' => now()->subHour()]));
        $orders->each(fn (Order $order) => $marker->markOrder($context['tenant']->id, $context['store']->id, $order->id, ReconciliationDirtySubject::REASON_ORDER_EVENT, now()));

        $this->artisan('reconciliation:work --batch=3 --stop-when-empty')
            ->expectsOutput('reconciliation worker stopped: batches=4 claimed=7 processed=7 unchanged=0 skipped=0 failed=0')
            ->assertSuccessful();
        $this->assertSame(0, ReconciliationDirtySubject::query()->count());
        $runs = ReconciliationRun::query()->count();

        $orders->each(fn (Order $order) => $marker->markOrder($context['tenant']->id, $context['store']->id, $order->id, ReconciliationDirtySubject::REASON_NIGHTLY_SWEEP, now()));
        $this->artisan('reconciliation:work --batch=50 --stop-when-empty')
            ->expectsOutput('reconciliation worker stopped: batches=2 claimed=7 processed=7 unchanged=7 skipped=0 failed=0')
            ->assertSuccessful();
        $this->assertSame($runs, ReconciliationRun::query()->count());
    }

    public function test_a_pending_order_is_not_rechecked_again_and_again_before_its_grace_deadline(): void
    {
        $context = $this->context();
        $order = $this->order($context, ['paid_marked_at' => now()->subMinutes(5)]);
        app(DirtySubjectMarker::class)->markOrder($context['tenant']->id, $context['store']->id, $order->id, ReconciliationDirtySubject::REASON_ORDER_EVENT, now());

        $this->artisan('reconciliation:work --stop-when-empty')->assertSuccessful();

        $subject = ReconciliationDirtySubject::query()->sole();
        $this->assertSame(ReconciliationDirtySubject::REASON_GRACE_DEADLINE, $subject->reason);
        $this->assertTrue($subject->due_at->greaterThan(now()));
        $this->assertSame(1, ReconciliationRun::query()->count());
    }

    private function state(Order $order): ReconciliationSubjectState
    {
        return ReconciliationSubjectState::query()->where('subject_id', $order->id)->sole();
    }
}
