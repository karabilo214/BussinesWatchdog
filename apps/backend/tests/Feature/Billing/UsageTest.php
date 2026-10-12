<?php

namespace Tests\Feature\Billing;

use App\Models\CheckRun;
use App\Models\CheckScenario;
use App\Models\EventInbox;
use App\Models\Integration;
use App\Models\Subscription;
use App\Support\Billing\EntitlementCalculator;
use App\Support\Billing\FeatureCatalog;
use App\Support\Billing\UsageMeter;
use App\Support\Browser\ScenarioDefinition;
use App\Support\Ingest\EventIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Notifications\NotificationTestContext;
use Tests\TestCase;

class UsageTest extends TestCase
{
    use NotificationTestContext;
    use RefreshDatabase;

    /** @var array<string, string> */
    private array $eventIds = [];

    public function test_manual_runs_stop_at_the_daily_limit_and_reset_at_the_tenants_midnight(): void
    {
        Carbon::setTestNow('2026-10-12T10:00:00Z');
        config()->set('watchdog.billing.enforcement', EntitlementCalculator::MODE_ENFORCE);
        $context = $this->context();
        $context['store']->forceFill(['base_url' => 'https://shop.example.test', 'status' => 'active', 'browser_enabled' => true, 'verified_at' => now()])->save();
        $this->subscribe($context, 2);
        $scenario = $this->scenario($context);

        $this->manualRun($context, $scenario)->assertStatus(202);
        $this->manualRun($context, $scenario)->assertStatus(429)->assertJsonPath('code', 'manual_check_rate_limited');
        $this->finishRuns();
        Carbon::setTestNow('2026-10-12T10:05:00Z');
        $this->manualRun($context, $scenario)->assertStatus(202);
        $this->finishRuns();
        Carbon::setTestNow('2026-10-12T10:10:00Z');

        $this->manualRun($context, $scenario)
            ->assertStatus(429)
            ->assertHeader('Retry-After', (string) (10 * 3600 + 50 * 60))
            ->assertJsonPath('code', 'usage_limit_reached')
            ->assertJsonPath('errors.0.limit', 'manual_runs_per_day')
            ->assertJsonPath('errors.0.value', 2)
            ->assertJsonPath('errors.0.used', 2)
            ->assertJsonPath('errors.0.resets_at', '2026-10-12T21:00:00.000000Z');
        $this->assertSame(2, CheckRun::query()->where('trigger', CheckRun::TRIGGER_MANUAL)->count());

        Carbon::setTestNow('2026-10-12T21:00:00Z');
        $this->manualRun($context, $scenario)->assertStatus(202);
    }

    public function test_pilot_mode_counts_without_limiting(): void
    {
        $context = $this->context();
        $meter = app(UsageMeter::class);

        $this->assertTrue($meter->consume($context['tenant']->id, UsageMeter::MANUAL_RUNS, 'op-1', null, 'manual_check_run'));
        $this->assertTrue($meter->consume($context['tenant']->id, UsageMeter::MANUAL_RUNS, 'op-1', null, 'manual_check_run'));
        $this->assertTrue($meter->consume($context['tenant']->id, UsageMeter::MANUAL_RUNS, 'op-2', null, 'manual_check_run'));
        $this->assertFalse($meter->consume($context['tenant']->id, UsageMeter::MANUAL_RUNS, 'op-3', 2, 'manual_check_run'));

        $this->assertSame(2, $meter->current($context['tenant']->id, UsageMeter::MANUAL_RUNS)['used']);
        $this->assertSame(2, DB::table('usage_entries')->count());
    }

    public function test_accepted_store_events_are_counted_per_month_once_and_provider_or_synthetic_events_are_not(): void
    {
        Carbon::setTestNow('2026-09-30T20:30:00Z');
        $context = $this->context();
        $store = $this->integration($context, Integration::SOURCE_STORE_REPORTED, 'woocommerce');
        $ingestor = app(EventIngestor::class);

        $ingestor->ingest($store, [$this->event('e1'), $this->event('e2'), $this->event('e3', true)], null, (string) Str::uuid());
        $ingestor->ingest($store, [$this->event('e1'), $this->event('e4', false, ['data' => 'invalid'])], null, (string) Str::uuid());
        $ingestor->ingest($context['integration'], [$this->event('p1')], null, (string) Str::uuid());

        $usage = app(UsageMeter::class)->current($context['tenant']->id, UsageMeter::STORE_EVENTS);
        $this->assertSame(2, $usage['used']);
        $this->assertSame('2026-09-30T21:00:00+00:00', $usage['period_end']->toAtomString());
        $this->assertSame(1, EventInbox::query()->where('integration_id', $context['integration']->id)->count());

        Carbon::setTestNow('2026-09-30T21:00:00Z');
        $ingestor->ingest($store, [$this->event('e5')], null, (string) Str::uuid());

        $this->asMember($context)->getJson('/api/v1/entitlements')
            ->assertOk()
            ->assertJsonPath('usage.monthly_event_budget.used', 1)
            ->assertJsonPath('usage.monthly_event_budget.period_start', '2026-09-30T21:00:00.000000Z')
            ->assertJsonPath('usage.monthly_event_budget.period_end', '2026-10-31T22:00:00.000000Z')
            ->assertJsonPath('usage.manual_runs_per_day.used', 0);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function subscribe(array $context, int $manualRuns): void
    {
        Subscription::query()->create([
            'tenant_id' => $context['tenant']->id,
            'provider' => 'paddle',
            'plan_code' => 'start',
            'entitlements' => [
                'features' => [FeatureCatalog::CHECKOUT_MONITORING],
                'limits' => [FeatureCatalog::MAX_ACTIVE_STORES => 1, FeatureCatalog::MAX_EXTRA_STORES => 0, FeatureCatalog::MANUAL_RUNS_PER_DAY => $manualRuns],
            ],
            'extra_store_quantity' => 0,
            'status' => Subscription::STATUS_ACTIVE,
            'period_end' => now()->addMonth(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function scenario(array $context): CheckScenario
    {
        return CheckScenario::query()->create([
            'tenant_id' => $context['tenant']->id,
            'store_id' => $context['store']->id,
            'name' => 'Payment form',
            'mode' => CheckScenario::MODE_PAYMENT_FORM,
            'version' => 1,
            'enabled' => true,
            'adapter_version' => ScenarioDefinition::ADAPTER_VERSION,
            'definition' => ['product_url' => 'https://shop.example.test/product/x'],
            'interval_seconds' => 900,
            'next_due_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function manualRun(array $context, CheckScenario $scenario): TestResponse
    {
        return $this->asMember($context)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/stores/{$context['store']->id}/checks", ['scenario_id' => $scenario->id]);
    }

    private function finishRuns(): void
    {
        CheckRun::query()->update(['status' => CheckRun::STATUS_PASSED]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function integration(array $context, string $authority, string $provider): Integration
    {
        return Integration::query()->create([
            'tenant_id' => $context['tenant']->id,
            'store_id' => $context['store']->id,
            'provider' => $provider,
            'install_id' => fake()->uuid(),
            'mode' => 'live',
            'source_authority' => $authority,
            'status' => Integration::STATUS_ACTIVE,
            'capabilities' => [],
            'connector_version' => '1.0.0',
            'health' => [],
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function event(string $eventId, bool $synthetic = false, array $overrides = []): array
    {
        $observedAt = now()->subMinute();
        $this->eventIds[$eventId] ??= (string) Str::uuid();

        return array_merge([
            'schema_version' => '1.0',
            'event_id' => $this->eventIds[$eventId],
            'type' => EventInbox::EVENT_ORDER_SNAPSHOT,
            'aggregate_type' => EventInbox::AGGREGATE_ORDER,
            'aggregate_id' => 'order-'.$eventId,
            'aggregate_revision' => 1,
            'occurred_at' => $observedAt->copy()->subSecond()->toJSON(),
            'observed_at' => $observedAt->toJSON(),
            'is_synthetic' => $synthetic,
            'data' => [
                'status' => 'processing',
                'currency' => 'EUR',
                'currency_exponent' => 2,
                'total_minor' => '18400',
                'payment_expected' => true,
            ],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function asMember(array $context): static
    {
        return $this->actingAs($context['user'])->withSession(['active_tenant_id' => $context['tenant']->id]);
    }
}
