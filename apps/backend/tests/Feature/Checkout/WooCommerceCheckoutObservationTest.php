<?php

namespace Tests\Feature\Checkout;

use App\Http\Controllers\Api\V1\Ingest\EventsController;
use App\Models\CheckoutObservationWindow;
use App\Models\CheckScenario;
use App\Models\EventInbox;
use App\Models\Incident;
use App\Models\Integration;
use App\Models\IntegrationCredential;
use App\Models\PaymentAttemptWindow;
use App\Models\Store;
use App\Models\Tenant;
use App\Support\Browser\ScenarioDefinition;
use App\Support\Checkout\CheckoutObservationEvaluator;
use App\Support\Ingest\EventInboxProcessor;
use App\Support\Stores\StoreCoverage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class WooCommerceCheckoutObservationTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Integration $plugin;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::query()->create(['name' => 'Kaffee', 'timezone' => 'Europe/Berlin']);
        $this->store = Store::query()->create(['tenant_id' => $tenant->id, 'name' => 'Shop', 'base_url' => 'https://shop.example.test', 'timezone' => 'Europe/Berlin', 'default_currency' => 'EUR', 'platform' => 'woocommerce']);
        $this->store->forceFill(['status' => 'active', 'verified_at' => now(), 'browser_enabled' => true])->save();
        $this->plugin = Integration::query()->create([
            'tenant_id' => $tenant->id, 'store_id' => $this->store->id, 'provider' => 'woocommerce', 'install_id' => (string) Str::uuid(), 'mode' => 'live',
            'source_authority' => Integration::SOURCE_STORE_REPORTED, 'status' => Integration::STATUS_ACTIVE, 'capabilities' => [], 'connector_version' => '0.3.0', 'health' => [],
        ]);
        $this->secret = random_bytes(32);
        IntegrationCredential::query()->create([
            'tenant_id' => $tenant->id, 'store_id' => $this->store->id, 'integration_id' => $this->plugin->id, 'kind' => IntegrationCredential::KIND_PLUGIN_HMAC,
            'key_id' => 'bwk_obs_key', 'ciphertext' => Crypt::encryptString(base64_encode($this->secret)), 'key_version' => 1,
            'fingerprint' => hash('sha256', $this->secret), 'status' => IntegrationCredential::STATUS_ACTIVE, 'created_at' => now(),
        ]);
    }

    public function test_the_heartbeat_tells_the_plugin_whether_to_observe(): void
    {
        $this->signed('/api/v1/ingest/heartbeat', ['backlog_count' => 0, 'plugin_version' => '0.3.0'])->assertOk()->assertJsonPath('checkout_observation.observe', true);

        $this->store->forceFill(['status' => 'paused'])->save();
        Cache::flush();
        $this->signed('/api/v1/ingest/heartbeat', ['backlog_count' => 0, 'plugin_version' => '0.3.0'])->assertOk()->assertJsonPath('checkout_observation.observe', false);
    }

    public function test_plugin_windows_land_in_the_observation_windows_and_only_newer_revisions_replace_them(): void
    {
        $start = $this->windowAgo(30);

        $this->ingest($this->event($start, 1, ['checkout_rendered' => 4, 'checkout_rendered_noscript' => 1, 'script_loaded' => 3, 'form_ready' => 3, 'submit_clicked' => 2, 'finish_rendered' => 1, 'finish_reached' => 1]))->assertJsonPath('results.0.status', EventsController::RESULT_ACCEPTED);
        $window = CheckoutObservationWindow::query()->sole();
        $this->assertSame([4, 1, 3, 3, 2, 1, 1, 1], [$window->confirm_rendered, $window->confirm_rendered_noscript, $window->script_loaded, $window->form_ready, $window->submit_clicked, $window->finish_rendered, $window->finish_reached, $window->source_revision]);

        $this->ingest($this->event($start, 3, ['checkout_rendered' => 5, 'script_loaded' => 4]));
        $this->ingest($this->event($start, 2, ['checkout_rendered' => 99]));
        $window->refresh();
        $this->assertSame([5, 4, 0, 3], [$window->confirm_rendered, $window->script_loaded, $window->submit_clicked, $window->source_revision], 'revision 3 replaces, the late revision 2 is ignored');

        $wrongId = $this->event($start, 4, []);
        $wrongId['aggregate_id'] = $start->toJSON();
        $this->ingest($wrongId)->assertJsonPath('results.0.status', EventsController::RESULT_QUARANTINED);
    }

    public function test_plugin_submits_without_orders_open_the_incident_and_reported_attempts_resolve_it(): void
    {
        $this->ingest($this->event($this->windowAgo(30), 1, ['checkout_rendered' => 3, 'script_loaded' => 3, 'form_ready' => 3, 'submit_clicked' => 3, 'submit_stalled' => 1]));

        app(CheckoutObservationEvaluator::class)->evaluateAll();
        $incident = Incident::query()->sole();
        $this->assertSame([CheckoutObservationEvaluator::RULE_SUBMIT_FAILING, Incident::STATE_OPEN], [$incident->title_code, $incident->state]);

        PaymentAttemptWindow::query()->create([
            'tenant_id' => $this->store->tenant_id, 'store_id' => $this->store->id, 'integration_id' => $this->plugin->id, 'payment_method' => 'stripe',
            'window_start' => $this->windowAgo(10), 'window_end' => $this->windowAgo(5), 'paid' => 1,
            'failure_classes' => [], 'source_revision' => 1, 'payload_hash' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now(),
        ]);
        app(CheckoutObservationEvaluator::class)->evaluateAll();
        $this->assertSame(Incident::STATE_RESOLVED, $incident->refresh()->state);
    }

    public function test_coverage_and_the_browser_check_for_woocommerce(): void
    {
        $coverage = fn (): string => app(StoreCoverage::class)->forStores(collect([$this->store->refresh()]))[$this->store->id]['coverage']['checkout_script']['state'];
        $this->assertSame('waiting', $coverage());

        $scenario = CheckScenario::query()->create([
            'tenant_id' => $this->store->tenant_id, 'store_id' => $this->store->id, 'name' => 'Payment form', 'mode' => CheckScenario::MODE_PAYMENT_FORM, 'version' => 1, 'enabled' => true,
            'adapter_version' => ScenarioDefinition::ADAPTER_VERSION, 'definition' => ['product_url' => 'https://shop.example.test/product/x'], 'interval_seconds' => 900, 'next_due_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $snapshot = app(ScenarioDefinition::class)->snapshot($scenario, $this->store);
        $this->assertTrue($snapshot['expect_checkout_script']);
        $this->assertSame([], $snapshot['network_policy']['quiet_paths'], 'the plugin script reports to the shop itself');

        $this->ingest($this->event($this->windowAgo(30), 1, ['checkout_rendered' => 1, 'script_loaded' => 1]));
        $this->assertSame('observing', $coverage());

        $this->plugin->forceFill(['status' => Integration::STATUS_REVOKED])->save();
        Integration::query()->create([
            'tenant_id' => $this->store->tenant_id, 'store_id' => $this->store->id, 'provider' => 'woocommerce_api', 'mode' => 'live',
            'source_authority' => Integration::SOURCE_STORE_REPORTED, 'status' => Integration::STATUS_ACTIVE, 'capabilities' => [], 'connector_version' => 'x', 'health' => [],
        ]);
        Cache::flush();
        $this->assertSame('needs_plugin', $coverage());
        $this->assertFalse(app(ScenarioDefinition::class)->snapshot($scenario, $this->store)['expect_checkout_script']);
    }

    private function windowAgo(int $minutes): Carbon
    {
        $at = Carbon::now()->subMinutes($minutes)->getTimestamp();

        return Carbon::createFromTimestampUTC($at - $at % 300);
    }

    /**
     * @param  array<string, int>  $counts
     * @return array<string, mixed>
     */
    private function event(Carbon $start, int $revision, array $counts): array
    {
        $counters = array_fill_keys(['checkout_rendered', 'checkout_rendered_noscript', 'finish_rendered', 'script_loaded', 'form_ready', 'returned_with_error', 'submit_clicked', 'submit_stalled', 'finish_reached', 'js_errors'], 0);
        $windowStart = $start->copy()->utc()->format('Y-m-d\TH:i:s\Z');

        return [
            'schema_version' => '1.0',
            'event_id' => (string) Str::uuid(),
            'type' => EventInbox::EVENT_CHECKOUT_OBSERVATION,
            'aggregate_type' => EventInbox::AGGREGATE_CHECKOUT,
            'aggregate_id' => 'observation:'.$windowStart,
            'aggregate_revision' => $revision,
            'occurred_at' => $start->copy()->addMinutes(5)->toJSON(),
            'observed_at' => Carbon::now()->toJSON(),
            'is_synthetic' => false,
            'data' => ['window_start' => $windowStart, 'window_end' => $start->copy()->addMinutes(5)->utc()->format('Y-m-d\TH:i:s\Z'), ...array_merge($counters, $counts)],
        ];
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function ingest(array $event): TestResponse
    {
        $response = $this->signed('/api/v1/ingest/events', ['events' => [$event]]);

        EventInbox::query()->where('status', '!=', 'processed')->pluck('id')->each(fn (string $id) => app(EventInboxProcessor::class)->processReceived($id));

        return $response;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function signed(string $path, array $payload): TestResponse
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) Carbon::now()->getTimestamp();
        $nonce = (string) Str::uuid();
        $signature = hash_hmac('sha256', implode("\n", ['v1', $timestamp, strtolower($nonce), 'POST', $path, hash('sha256', $body)]), $this->secret);

        return $this->call('POST', $path, [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_BW_KEY_ID' => 'bwk_obs_key', 'HTTP_X_BW_TIMESTAMP' => $timestamp,
            'HTTP_X_BW_NONCE' => $nonce, 'HTTP_X_BW_SIGNATURE' => $signature, 'HTTP_X_BW_SIGNATURE_VERSION' => '1',
        ], $body);
    }
}
