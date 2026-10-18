<?php

namespace Tests\Feature\Stores;

use App\Http\Controllers\Api\V1\Ingest\EventsController;
use App\Jobs\SyncIntegration;
use App\Models\EventInbox;
use App\Models\Incident;
use App\Models\Integration;
use App\Models\IntegrationCredential;
use App\Models\ReconciliationDirtySubject;
use App\Models\Store;
use App\Models\Tenant;
use App\Support\Billing\StoreCapacity;
use App\Support\Checkout\CheckoutObservation;
use App\Support\Integrations\ConnectorFreshness;
use App\Support\Integrations\IntegrationSyncRunner;
use App\Support\Reconciliation\DirtySubjectMarker;
use App\Support\Reconciliation\DirtySubjectProcessor;
use App\Support\Stores\StoreCoverage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class StoreCapacityPauseTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Store $store;

    private Integration $plugin;

    private Integration $stripe;

    private Integration $api;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Http::preventStrayRequests();
        Carbon::setTestNow('2026-01-15T12:00:00Z');

        $this->tenant = Tenant::query()->create(['name' => 'Paused Org', 'timezone' => 'Europe/Berlin']);
        $this->store = $this->store('Main Shop', 'https://main.example.test');
        $other = $this->store('Other Shop', 'https://other.example.test');
        $this->plugin = $this->integration($this->store, 'woocommerce', Integration::SOURCE_STORE_REPORTED);
        $this->stripe = $this->integration($this->store, 'stripe', Integration::SOURCE_INDEPENDENT_PROVIDER);
        $this->api = $this->integration($other, 'woocommerce_api', Integration::SOURCE_STORE_REPORTED);
        $this->secret = random_bytes(32);
        IntegrationCredential::query()->create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'integration_id' => $this->plugin->id,
            'kind' => IntegrationCredential::KIND_PLUGIN_HMAC,
            'key_id' => 'bwk_capacity_test',
            'ciphertext' => Crypt::encryptString(base64_encode($this->secret)),
            'key_version' => 1,
            'fingerprint' => hash('sha256', $this->secret),
            'status' => IntegrationCredential::STATUS_ACTIVE,
            'created_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_paused_store_events_are_skipped_and_nothing_is_kept(): void
    {
        $this->pause($this->store);

        $this->signed('/api/v1/ingest/events', ['events' => [$this->event('33333333-3333-4333-8333-333333333333')]])
            ->assertStatus(207)
            ->assertJsonPath('results.0.index', 0)
            ->assertJsonPath('results.0.event_id', '33333333-3333-4333-8333-333333333333')
            ->assertJsonPath('results.0.status', EventsController::RESULT_SKIPPED)
            ->assertJsonPath('results.0.code', StoreCapacity::ERROR_PAUSED);
        $this->assertSame(0, EventInbox::query()->count());

        $this->signed('/api/v1/ingest/heartbeat', ['backlog_count' => 0])
            ->assertOk()
            ->assertJsonPath('store_paused', true)
            ->assertJsonPath('backfill_requested_at', null)
            ->assertJsonPath('backfill_since', null)
            ->assertJsonPath('checkout_observation.observe', false);
        $this->assertSame('capacity_paused', app(CheckoutObservation::class)->decide($this->plugin->id)['reason']);
    }

    public function test_paused_store_is_not_synced_evaluated_or_reconciled(): void
    {
        $this->pause($this->store);
        $this->plugin->forceFill(['last_heartbeat_at' => now()->subDay(), 'created_at' => now()->subDays(2)])->save();
        $marker = app(DirtySubjectMarker::class);
        $marker->markStoreUnmatchedPayments($this->tenant->id, $this->store->id, ReconciliationDirtySubject::REASON_NIGHTLY_SWEEP, now());
        $runner = app(IntegrationSyncRunner::class);

        $this->assertSame([], $runner->due('stripe')->pluck('id')->all());
        $this->assertSame([$this->api->id], $runner->due('woocommerce_api')->pluck('id')->all());
        $this->assertFalse($runner->eligible($this->stripe));
        (new SyncIntegration($this->stripe->id, IntegrationSyncRunner::MODE_AUDIT))->handle($runner);

        app(ConnectorFreshness::class)->checkAll();
        $this->assertFalse(app(ConnectorFreshness::class)->check($this->plugin->id));
        $this->assertSame(0, Incident::query()->where('store_id', $this->store->id)->count(), 'A paused store raises no freshness incident.');

        $this->assertSame(0, app(DirtySubjectProcessor::class)->processDue()['claimed']);
        $this->assertSame(1, ReconciliationDirtySubject::query()->where('store_id', $this->store->id)->count(), 'Waiting subjects stay for after the pause.');
    }

    public function test_paused_store_shows_paused_and_unknown_never_healthy(): void
    {
        $this->pause($this->store);
        $this->plugin->forceFill(['health' => ['freshness' => ['state' => ConnectorFreshness::STATE_FRESH]]])->save();

        $coverage = app(StoreCoverage::class)->forStores(Store::query()->whereKey($this->store->id)->get())[$this->store->id]['coverage'];

        $this->assertSame(StoreCoverage::CAPACITY_PAUSED, $coverage['connector']['state']);
        $this->assertSame(StoreCoverage::CAPACITY_PAUSED, $coverage['money']['state']);
        $this->assertSame(StoreCoverage::CAPACITY_PAUSED, $coverage['payment_attempts']['state']);
        $this->assertSame(StoreCoverage::CAPACITY_PAUSED, $coverage['checkout_script']['state']);
    }

    public function test_resuming_catches_the_paused_period_up(): void
    {
        Queue::fake();
        $this->pause($this->store);
        $this->pause(Store::query()->whereKey($this->api->store_id)->firstOrFail());
        $this->api->forceFill(['last_successful_sync_at' => now()->subDays(3), 'created_at' => now()->subDays(5)])->save();

        app(StoreCapacity::class)->settle($this->tenant->id);

        $this->assertSame(0, Store::query()->whereNotNull('capacity_paused_at')->count());
        $this->assertSame(['from' => now()->subHour()->toJSON(), 'until' => now()->toJSON()], StoreCapacity::recentPause($this->store->refresh()), 'The customer is told that data of the paused period may deviate.');
        Queue::assertPushed(SyncIntegration::class, fn (SyncIntegration $job): bool => $job->integrationId === $this->stripe->id && $job->mode === IntegrationSyncRunner::MODE_AUDIT);
        Queue::assertPushed(SyncIntegration::class, fn (SyncIntegration $job): bool => $job->integrationId === $this->api->id && $job->mode === IntegrationSyncRunner::MODE_AUDIT);
        Queue::assertNotPushed(SyncIntegration::class, fn (SyncIntegration $job): bool => $job->integrationId === $this->plugin->id);

        $this->signed('/api/v1/ingest/heartbeat', ['backlog_count' => 0])
            ->assertOk()
            ->assertJsonPath('store_paused', false)
            ->assertJsonPath('backfill_requested_at', now()->toJSON())
            ->assertJsonPath('backfill_since', now()->subHour()->toJSON());

        $this->assertNotSame(ConnectorFreshness::STATE_STALE, app(ConnectorFreshness::class)->assess($this->api->refresh())['state'], 'The paused period is not read as a failing sync.');
        Carbon::setTestNow(now()->addMinutes((int) config('watchdog.woocommerce_api.stale_after_minutes') + 1));
        $this->assertSame(ConnectorFreshness::STATE_STALE, app(ConnectorFreshness::class)->assess($this->api->refresh())['state'], 'The wait starts again on resume, it is not lifted.');

        $this->signed('/api/v1/ingest/events', ['events' => [$this->event('44444444-4444-4444-8444-444444444444')]])
            ->assertAccepted()
            ->assertJsonPath('results.0.status', EventsController::RESULT_ACCEPTED);

        app(StoreCapacity::class)->settle($this->tenant->id);
        Queue::assertPushed(SyncIntegration::class, 2);

        Carbon::setTestNow(now()->addDays(StoreCapacity::DEVIATION_NOTICE_DAYS + 1));
        $this->assertNull(StoreCapacity::recentPause($this->store->refresh()));
    }

    private function pause(Store $store): void
    {
        $store->forceFill(['capacity_paused_at' => now()->subHour()])->save();
    }

    private function store(string $name, string $url): Store
    {
        return Store::query()->create([
            'tenant_id' => $this->tenant->id,
            'name' => $name,
            'base_url' => $url,
            'platform' => 'woocommerce',
            'status' => 'active',
            'timezone' => 'Europe/Berlin',
            'default_currency' => 'EUR',
        ]);
    }

    private function integration(Store $store, string $provider, string $authority): Integration
    {
        return Integration::query()->create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $store->id,
            'provider' => $provider,
            'install_id' => (string) Str::uuid(),
            'mode' => 'live',
            'source_authority' => $authority,
            'status' => Integration::STATUS_ACTIVE,
            'capabilities' => [],
            'connector_version' => '1.0.0',
            'health' => [],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function event(string $eventId): array
    {
        return [
            'schema_version' => '1.0',
            'event_id' => $eventId,
            'type' => EventInbox::EVENT_ORDER_SNAPSHOT,
            'aggregate_type' => EventInbox::AGGREGATE_ORDER,
            'aggregate_id' => 'order-1001',
            'aggregate_revision' => 1,
            'occurred_at' => now()->subMinutes(2)->toJSON(),
            'observed_at' => now()->subMinute()->toJSON(),
            'is_synthetic' => false,
            'data' => [
                'status' => 'processing',
                'currency' => 'EUR',
                'currency_exponent' => 2,
                'total_minor' => '18400',
                'payment_expected' => true,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function signed(string $path, array $payload): TestResponse
    {
        $timestamp = (string) now()->getTimestamp();
        $nonce = (string) Str::uuid();
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        return $this->call('POST', $path, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_BW_KEY_ID' => 'bwk_capacity_test',
            'HTTP_X_BW_TIMESTAMP' => $timestamp,
            'HTTP_X_BW_NONCE' => $nonce,
            'HTTP_X_BW_SIGNATURE' => hash_hmac('sha256', implode("\n", ['v1', $timestamp, $nonce, 'POST', $path, hash('sha256', $body)]), $this->secret),
            'HTTP_X_BW_SIGNATURE_VERSION' => '1',
        ], $body);
    }
}
