<?php

namespace Tests\Feature\Billing;

use App\Models\CheckScenario;
use App\Models\Incident;
use App\Models\ReconciliationFinding;
use App\Models\ReconciliationRun;
use App\Models\Subscription;
use App\Support\Billing\FeatureCatalog;
use App\Support\Billing\HistoryPurger;
use App\Support\Browser\ScenarioDefinition;
use App\Support\Reconciliation\OrderReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Notifications\NotificationTestContext;
use Tests\TestCase;

class HistoryRetentionTest extends TestCase
{
    use NotificationTestContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 03:45:00');
        config()->set('watchdog.billing.enforcement', 'enforce');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_history_older_than_the_package_retention_is_hidden_and_returns_with_a_longer_package(): void
    {
        $context = $this->context('admin');
        $this->subscribe($context['tenant']->id, 30);
        $scenario = $this->scenario($context);
        $oldRun = $this->checkRun($context, $scenario, 40);
        $newRun = $this->checkRun($context, $scenario, 3);
        $oldClosed = $this->incident($context, 'resolved', 40);
        $oldOpen = $this->incident($context, 'open', 40);
        $oldOrder = $this->order($context, ['source_created_at' => now()->subDays(40), 'source_updated_at' => now()->subDays(40)]);
        $api = fn () => $this->actingAs($context['user'])->withSession(['active_tenant_id' => $context['tenant']->id]);

        $runs = $api()->getJson("/api/v1/stores/{$context['store']->id}/checks")->assertOk()->json('data');
        $this->assertSame([$newRun], array_column($runs, 'id'));
        $api()->getJson("/api/v1/checks/{$oldRun}")->assertNotFound();
        $incidents = array_column($api()->getJson('/api/v1/incidents')->assertOk()->json('data'), 'id');
        $this->assertContains($oldOpen, $incidents);
        $this->assertNotContains($oldClosed, $incidents);
        $api()->getJson("/api/v1/incidents/{$oldClosed}")->assertNotFound();
        $api()->getJson("/api/v1/orders/{$oldOrder->id}")->assertNotFound();

        Subscription::query()->where('tenant_id', $context['tenant']->id)->update(['entitlements' => json_encode($this->entitlements(365))]);

        $this->assertCount(2, $api()->getJson("/api/v1/stores/{$context['store']->id}/checks")->json('data'));
        $api()->getJson("/api/v1/incidents/{$oldClosed}")->assertOk();
        $api()->getJson("/api/v1/orders/{$oldOrder->id}")->assertOk();
    }

    public function test_history_is_deleted_thirty_days_after_leaving_the_retention_keeping_current_results_and_open_incidents(): void
    {
        $context = $this->context();
        $this->subscribe($context['tenant']->id, 30);
        $scenario = $this->scenario($context);
        $purged = $this->checkRun($context, $scenario, 70);
        $hidden = $this->checkRun($context, $scenario, 45);
        $closedOld = $this->incident($context, 'resolved', 70);
        $openOld = $this->incident($context, 'open', 70);
        $order = $this->order($context, ['paid_marked_at' => now()->subDays(80), 'source_created_at' => now()->subDays(80), 'source_updated_at' => now()->subDays(80)]);
        Carbon::setTestNow(now()->subDays(80));
        $current = app(OrderReconciliationService::class)->evaluate($order);
        Carbon::setTestNow('2026-10-20 03:45:00');
        $oldRun = ReconciliationRun::query()->create(['tenant_id' => $order->tenant_id, 'store_id' => $order->store_id, 'status' => 'completed', 'algorithm_version' => 'v0', 'config_version' => 1, 'scope' => ['order_id' => $order->id], 'coverage_snapshot' => [], 'counters' => [], 'created_at' => now()->subDays(90)]);
        ReconciliationFinding::query()->create(['tenant_id' => $order->tenant_id, 'store_id' => $order->store_id, 'run_id' => $oldRun->id, 'order_id' => $order->id, 'rule_code' => 'MONEY_CAPTURE_MISSING', 'status' => 'mismatch', 'reason_code' => 'x', 'evidence' => [], 'config_snapshot' => [], 'evaluated_at' => now()->subDays(90)]);

        $result = app(HistoryPurger::class)->purge();

        $this->assertSame(1, $result['tenants']);
        $this->assertDatabaseMissing('check_runs', ['id' => $purged]);
        $this->assertDatabaseHas('check_runs', ['id' => $hidden]);
        $this->assertDatabaseMissing('incidents', ['id' => $closedOld]);
        $this->assertDatabaseHas('incidents', ['id' => $openOld]);
        $this->assertDatabaseMissing('reconciliation_runs', ['id' => $oldRun->id]);
        $this->assertDatabaseHas('reconciliation_runs', ['id' => $current->id]);
        $this->assertTrue(ReconciliationFinding::query()->where('run_id', $current->id)->exists());
        $this->assertDatabaseHas('orders', ['id' => $order->id]);

        config()->set('watchdog.billing.enforcement', 'pilot');
        $this->assertSame(0, app(HistoryPurger::class)->purge()['tenants']);
    }

    /**
     * @return array{features: list<string>, limits: array<string, ?int>}
     */
    private function entitlements(int $days): array
    {
        return FeatureCatalog::normalize(['features' => [FeatureCatalog::CHECKOUT_MONITORING, FeatureCatalog::MONEY_RECONCILIATION], 'limits' => [FeatureCatalog::MAX_ACTIVE_STORES => 3, FeatureCatalog::RETENTION_DAYS => $days]]);
    }

    private function subscribe(string $tenantId, int $days): void
    {
        Subscription::query()->create([
            'tenant_id' => $tenantId, 'provider' => 'paddle', 'plan_code' => 'start', 'entitlements' => $this->entitlements($days), 'extra_store_quantity' => 0,
            'status' => Subscription::STATUS_ACTIVE, 'period_start' => now()->subDays(5), 'period_end' => now()->addDays(25), 'version' => 1,
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function scenario(array $context): CheckScenario
    {
        return CheckScenario::query()->create([
            'tenant_id' => $context['tenant']->id, 'store_id' => $context['store']->id, 'name' => 'Payment form', 'mode' => CheckScenario::MODE_PAYMENT_FORM,
            'version' => 1, 'enabled' => true, 'adapter_version' => ScenarioDefinition::ADAPTER_VERSION, 'definition' => ['product_url' => $context['store']->base_url.'/product/x'],
            'interval_seconds' => 900, 'next_due_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function checkRun(array $context, CheckScenario $scenario, int $daysAgo): string
    {
        $id = (string) Str::uuid7();
        DB::table('check_runs')->insert([
            'id' => $id, 'tenant_id' => $context['tenant']->id, 'store_id' => $context['store']->id, 'scenario_id' => $scenario->id, 'scenario_version' => 1,
            'trigger' => 'scheduled', 'dedupe_key' => 'run-'.$id, 'status' => 'passed', 'config_snapshot' => '{}', 'scheduled_at' => now()->subDays($daysAgo),
            'next_attempt_at' => now()->subDays($daysAgo), 'finished_at' => now()->subDays($daysAgo), 'created_at' => now()->subDays($daysAgo),
        ]);

        return $id;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function incident(array $context, string $state, int $daysAgo): string
    {
        return Incident::query()->create([
            'tenant_id' => $context['tenant']->id, 'store_id' => $context['store']->id, 'family' => 'money', 'component' => 'capture', 'fingerprint' => Str::random(20),
            'state' => $state, 'severity' => Incident::SEVERITY_WARNING, 'title_code' => 'MONEY_CAPTURE_MISSING', 'currency' => 'EUR',
            'first_seen_at' => now()->subDays($daysAgo + 1), 'last_seen_at' => now()->subDays($daysAgo), 'first_bad_at' => now()->subDays($daysAgo + 1),
            'resolved_at' => $state === 'resolved' ? now()->subDays($daysAgo) : null, 'revision' => 1, 'created_at' => now()->subDays($daysAgo + 1), 'updated_at' => now()->subDays($daysAgo),
        ])->id;
    }
}
