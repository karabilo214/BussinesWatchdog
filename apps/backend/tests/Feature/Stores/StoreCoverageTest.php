<?php

namespace Tests\Feature\Stores;

use App\Models\CheckRun;
use App\Models\CheckScenario;
use App\Models\Incident;
use App\Models\Integration;
use App\Models\PaymentAttemptWindow;
use App\Support\Browser\ScenarioDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Feature\Notifications\NotificationTestContext;
use Tests\TestCase;

class StoreCoverageTest extends TestCase
{
    use NotificationTestContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-09 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_store_without_sources_says_so_instead_of_looking_healthy(): void
    {
        $context = $this->context();
        $context['integration']->forceFill(['status' => Integration::STATUS_REVOKED])->save();

        $store = $this->asOwner($context)->getJson('/api/v1/stores/'.$context['store']->id)->assertOk();

        $store->assertJsonPath('coverage.connector.state', 'not_connected')
            ->assertJsonPath('coverage.money.state', 'provider_not_connected')
            ->assertJsonPath('coverage.payment_attempts.state', 'store_not_connected')
            ->assertJsonPath('coverage.browser_checks.state', 'store_not_verified')
            ->assertJsonPath('last_successful_check_at', null)
            ->assertJsonPath('active_incident_count', 0)
            ->assertHeader('ETag', '"1"');
    }

    public function test_a_fully_covered_store_reports_every_source_and_counts_active_incidents(): void
    {
        $context = $this->context();
        $context['store']->forceFill(['base_url' => 'https://shop.example.test', 'verified_at' => now(), 'browser_enabled' => true, 'status' => 'active'])->save();
        $connector = $this->connector($context, ['freshness' => ['state' => 'fresh'], 'heartbeat' => ['plugin_version' => '0.6.0']]);
        $this->window($context, $connector, now()->subMinutes(10));
        $scenario = $this->scenario($context);
        $this->finishedRun($scenario, CheckRun::STATUS_FAILED, now()->subHour());
        $this->finishedRun($scenario, CheckRun::STATUS_PASSED, now()->subMinutes(5));
        $this->incident($context, Incident::STATE_OPEN);
        $this->incident($context, Incident::STATE_ACKNOWLEDGED);
        $this->incident($context, Incident::STATE_RESOLVED);

        $list = $this->asOwner($context)->getJson('/api/v1/stores')->assertOk();
        $store = collect($list->json('data'))->firstWhere('id', $context['store']->id);

        $this->assertSame('fresh', $store['coverage']['connector']['state']);
        $this->assertSame('0.6.0', $store['coverage']['connector']['plugin_version']);
        $this->assertSame('reconciling', $store['coverage']['money']['state']);
        $this->assertSame([['provider' => 'stripe', 'status' => 'active']], $store['coverage']['money']['providers']);
        $this->assertSame('observing', $store['coverage']['payment_attempts']['state']);
        $this->assertSame('passing', $store['coverage']['browser_checks']['state']);
        $this->assertSame(now()->subMinutes(5)->toJSON(), $store['last_successful_check_at']);
        $this->assertSame(2, $store['active_incident_count']);
    }

    public function test_stale_store_data_is_reported_for_money_and_payment_attempts(): void
    {
        $context = $this->context();
        $this->connector($context, ['freshness' => ['state' => 'stale']]);

        $this->asOwner($context)->getJson('/api/v1/stores/'.$context['store']->id)
            ->assertJsonPath('coverage.connector.state', 'stale')
            ->assertJsonPath('coverage.money.state', 'store_data_stale')
            ->assertJsonPath('coverage.payment_attempts.state', 'store_data_stale');
    }

    public function test_responses_carry_a_request_id_and_store_integrations_are_listed(): void
    {
        $context = $this->context();
        $incoming = (string) Str::uuid();

        $response = $this->asOwner($context)->withHeader('X-Request-ID', $incoming)->getJson('/api/v1/stores/'.$context['store']->id.'/integrations')->assertOk();
        $this->assertSame($incoming, $response->headers->get('X-Request-ID'));
        $this->assertSame([$context['integration']->id], array_column($response->json('data'), 'id'));

        $generated = $this->asOwner($context)->withHeader('X-Request-ID', 'not-a-uuid')->getJson('/api/v1/stores')->headers->get('X-Request-ID');
        $this->assertTrue(Str::isUuid($generated));
        $this->asOwner($this->context())->getJson('/api/v1/stores/'.$context['store']->id.'/integrations')->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $health
     */
    private function connector(array $context, array $health): Integration
    {
        return Integration::query()->create([
            'tenant_id' => $context['tenant']->id,
            'store_id' => $context['store']->id,
            'provider' => 'woocommerce',
            'install_id' => fake()->uuid(),
            'mode' => 'live',
            'source_authority' => Integration::SOURCE_STORE_REPORTED,
            'status' => Integration::STATUS_ACTIVE,
            'capabilities' => [],
            'connector_version' => '0.6.0',
            'health' => $health,
            'last_heartbeat_at' => now()->subMinutes(2),
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function window(array $context, Integration $connector, Carbon $start): void
    {
        PaymentAttemptWindow::query()->create([
            'tenant_id' => $context['tenant']->id,
            'store_id' => $context['store']->id,
            'integration_id' => $connector->id,
            'payment_method' => 'stripe',
            'window_start' => $start,
            'window_end' => $start->copy()->addMinutes(5),
            'paid' => 1,
            'failure_classes' => [],
            'source_revision' => 1,
            'payload_hash' => str_repeat('a', 64),
            'created_at' => now(),
            'updated_at' => now(),
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
            'definition' => ['product_url' => 'https://shop.example.test/p'],
            'interval_seconds' => 900,
            'next_due_at' => now()->addMinutes(10),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function finishedRun(CheckScenario $scenario, string $status, Carbon $finishedAt): void
    {
        CheckRun::query()->create([
            'tenant_id' => $scenario->tenant_id,
            'store_id' => $scenario->store_id,
            'scenario_id' => $scenario->id,
            'scenario_version' => 1,
            'trigger' => CheckRun::TRIGGER_SCHEDULED,
            'dedupe_key' => 'test:'.Str::uuid(),
            'status' => $status,
            'config_snapshot' => [],
            'scheduled_at' => $finishedAt->copy()->subMinute(),
            'started_at' => $finishedAt->copy()->subMinute(),
            'finished_at' => $finishedAt,
            'next_attempt_at' => $finishedAt,
            'created_at' => $finishedAt->copy()->subMinute(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function incident(array $context, string $state): void
    {
        Incident::query()->create([
            'tenant_id' => $context['tenant']->id,
            'store_id' => $context['store']->id,
            'family' => 'checkout',
            'component' => 'payment_form',
            'fingerprint' => 'fp-'.Str::random(10),
            'state' => $state,
            'severity' => Incident::SEVERITY_WARNING,
            'title_code' => 'CHECKOUT_FLOW_FAILED',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'resolved_at' => $state === Incident::STATE_RESOLVED ? now() : null,
            'revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function asOwner(array $context): static
    {
        return $this->actingAs($context['user'])->withSession(['active_tenant_id' => $context['tenant']->id]);
    }
}
