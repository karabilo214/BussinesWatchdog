<?php

namespace Tests\Feature\Checkout;

use App\Models\BrowserWorker;
use App\Models\CheckAttempt;
use App\Models\CheckoutObservationWindow;
use App\Models\CheckRun;
use App\Models\CheckScenario;
use App\Models\CheckStep;
use App\Models\Incident;
use App\Models\Integration;
use App\Models\IntegrationCredential;
use App\Models\Membership;
use App\Models\ObservedPaymentAttempt;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Browser\ScenarioDefinition;
use App\Support\Checkout\CheckoutObservationEvaluator;
use App\Support\Notifications\IncidentNotificationContentBuilder;
use App\Support\Notifications\NotificationPreferences;
use App\Support\Notifications\NotificationRenderer;
use App\Support\Notifications\RenderedNotification;
use App\Support\Providers\Shopware\ShopwareApp;
use App\Support\Providers\Shopware\ShopwareConnector;
use App\Support\Stores\StoreCoverage;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

class CheckoutObservationTest extends TestCase
{
    use RefreshDatabase;

    private const SHOP = 'https://shop.example.test';

    private Tenant $tenant;

    private Store $store;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-14 12:00:00', 'UTC'));
        $this->user = User::query()->create(['name' => 'Owner', 'email' => 'owner@example.test', 'password_hash' => Hash::make('very-secure-password'), 'locale' => 'ru']);
        $this->tenant = Tenant::query()->create(['name' => 'Kaffee', 'timezone' => 'Europe/Berlin']);
        Membership::query()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id, 'role' => 'owner']);
        $this->store = Store::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Shop', 'base_url' => self::SHOP, 'timezone' => 'Europe/Berlin', 'default_currency' => 'EUR', 'platform' => 'shopware']);
        $this->store->forceFill(['status' => 'active', 'verified_at' => now(), 'browser_enabled' => true])->save();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_only_an_observed_store_from_its_own_origin_gets_observe_true(): void
    {
        $app = $this->integration('app');
        $this->scenario(CheckScenario::ACCESS_GUEST);

        $this->getJson("/api/v1/observe/{$app->id}/config", ['Origin' => self::SHOP])
            ->assertOk()->assertJson(['observe' => true])->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson("/api/v1/observe/{$app->id}/config", ['Origin' => 'https://shop.registered.test'])
            ->assertJson(['observe' => true], 'the shop URL Shopware registered is the store too');
        $this->getJson("/api/v1/observe/{$app->id}/config", ['Origin' => 'https://evil.example.test'])->assertJson(['observe' => false]);
        $this->getJson('/api/v1/observe/'.Str::uuid().'/config', ['Origin' => self::SHOP])->assertJson(['observe' => false]);

        CheckScenario::query()->update(['checkout_access' => CheckScenario::ACCESS_STOP_BEFORE_SIGN_IN]);
        Cache::flush();
        $this->getJson("/api/v1/observe/{$app->id}/config", ['Origin' => self::SHOP])->assertJson(['observe' => false]);

        $key = $this->integration('key', $app->store_id === $this->store->id ? $this->otherStore() : $this->store);
        $this->getJson("/api/v1/observe/{$key->id}/config", ['Origin' => self::SHOP])->assertJson(['observe' => false]);
    }

    public function test_beacons_and_stages_count_only_for_an_observed_store_and_keep_nothing_about_the_visitor(): void
    {
        $app = $this->integration('app');
        $this->scenario(CheckScenario::ACCESS_TEST_CUSTOMER);
        $base = "/api/v1/observe/{$app->id}";

        $this->withoutHeader('Origin')->get("{$base}/r.gif?page=confirm", ['Referer' => self::SHOP.'/'])->assertOk()->assertHeader('Content-Type', 'image/gif');
        $this->withoutHeader('Origin')->get("{$base}/r.gif?page=confirm&js=0", ['Referer' => self::SHOP.'/']);
        $this->withoutHeader('Origin')->get("{$base}/r.gif?page=finish", ['Referer' => self::SHOP.'/']);
        $this->withoutHeader('Origin')->get("{$base}/r.gif?page=confirm", ['Referer' => 'https://evil.example.test/'])->assertOk();
        $this->call('POST', "{$base}/stages", [], [], [], ['HTTP_ORIGIN' => self::SHOP, 'CONTENT_TYPE' => 'text/plain'], json_encode(['page' => 'confirm', 'stages' => ['script_loaded', 'form_ready', 'submit_clicked', 'credit_card_number', 'script_loaded'], 'js_errors' => 500]))->assertNoContent();
        $this->call('POST', "{$base}/stages", [], [], [], ['HTTP_ORIGIN' => 'https://evil.example.test', 'CONTENT_TYPE' => 'text/plain'], json_encode(['page' => 'confirm', 'stages' => ['submit_clicked']]))->assertNoContent();
        $this->call('POST', "{$base}/stages", [], [], [], ['HTTP_ORIGIN' => self::SHOP, 'CONTENT_TYPE' => 'text/plain'], 'not json')->assertNoContent();

        $window = CheckoutObservationWindow::query()->sole();
        $this->assertSame(
            ['confirm_rendered' => 1, 'confirm_rendered_noscript' => 1, 'finish_rendered' => 1, 'script_loaded' => 1, 'form_ready' => 1, 'returned_with_error' => 0, 'submit_clicked' => 1, 'submit_stalled' => 0, 'finish_reached' => 0, 'js_errors' => 20],
            array_intersect_key($window->toArray(), array_flip(CheckoutObservationWindow::COUNTERS)),
        );
        $this->assertSame(['id', 'tenant_id', 'store_id', 'integration_id', 'window_start', ...CheckoutObservationWindow::COUNTERS, 'created_at', 'updated_at', 'source_revision', 'event_id'], array_keys(\DB::table('checkout_observation_windows')->first() ? (array) \DB::table('checkout_observation_windows')->first() : []), 'no column for an address, agent or visitor id');

        CheckScenario::query()->update(['checkout_access' => CheckScenario::ACCESS_STOP_BEFORE_SIGN_IN]);
        Cache::flush();
        $this->withoutHeader('Origin')->get("{$base}/r.gif?page=confirm", ['Referer' => self::SHOP.'/'])->assertOk();
        $this->assertSame(1, CheckoutObservationWindow::query()->sole()->confirm_rendered, 'a store that is not observed is not counted');
    }

    public function test_a_silent_script_and_submits_without_orders_open_incidents_that_resolve(): void
    {
        $app = $this->integration('app');
        $this->scenario(CheckScenario::ACCESS_GUEST);
        $this->attempt($app, 'c');
        $this->counts($app, ['confirm_rendered' => 6]);

        $this->artisan('checkout:observation-evaluate')->assertSuccessful();
        $silent = Incident::query()->sole();
        $this->assertSame([CheckoutObservationEvaluator::RULE_SCRIPT_SILENT, CheckoutObservationEvaluator::COMPONENT_SCRIPT, Incident::SEVERITY_INFO], [$silent->title_code, $silent->component, $silent->severity]);
        $text = $this->rendered($silent);
        $this->assertStringContainsString('6 раз', $text->body);
        $this->assertStringContainsString('Наблюдение за оформлением заказа не работает', $text->subject);

        $this->counts($app, ['script_loaded' => 1, 'submit_clicked' => 3, 'submit_stalled' => 1]);
        $this->artisan('checkout:observation-evaluate')->assertSuccessful();
        $this->assertSame(Incident::STATE_RESOLVED, $silent->refresh()->state);
        $submit = Incident::query()->where('component', CheckoutObservationEvaluator::COMPONENT_SUBMIT)->sole();
        $this->assertSame([CheckoutObservationEvaluator::RULE_SUBMIT_FAILING, Incident::SEVERITY_WARNING], [$submit->title_code, $submit->severity]);
        $this->assertStringContainsString('3 раз отправили заказ', $this->rendered($submit)->body);

        $this->attempt($app, 'a');
        $this->artisan('checkout:observation-evaluate')->assertSuccessful();
        $this->assertSame(Incident::STATE_RESOLVED, $submit->refresh()->state);

        $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => str_contains((string) $event->command, 'checkout:observation-evaluate'));
        $this->assertSame('*/5 * * * *', $event->expression);
    }

    public function test_the_app_archive_carries_the_script_and_templates_for_this_store(): void
    {
        $response = $this->actingAs($this->user)->withSession(['active_tenant_id' => $this->tenant->id])
            ->postJson("/api/v1/stores/{$this->store->id}/integrations/shopware-app", ['state_change_risk_acknowledged' => true])->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'bw-test-app');
        file_put_contents($path, $response->getContent());
        $zip = new ZipArchive;
        $zip->open($path);
        $names = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }

        $confirm = (string) $zip->getFromName('BusinessWatchdog/Resources/views/storefront/page/checkout/confirm/index.html.twig');
        $script = (string) $zip->getFromName('BusinessWatchdog/Resources/app/storefront/dist/storefront/js/business-watchdog/business-watchdog.js');
        $manifest = (string) $zip->getFromName('BusinessWatchdog/manifest.xml');
        $zip->close();
        @unlink($path);
        $integration = $response->headers->get('X-Integration-Id');

        $this->assertContains('BusinessWatchdog/Resources/views/storefront/page/checkout/finish/index.html.twig', $names);
        $this->assertStringContainsString('<version>'.ShopwareApp::APP_VERSION.'</version>', $manifest);
        $this->assertStringContainsString('data-endpoint="'.url('/api/v1/observe/'.$integration).'"', $confirm);
        $this->assertStringNotContainsString('__BW_OBSERVE_ENDPOINT__', $confirm);
        foreach (['localStorage', 'sessionStorage', 'document.cookie', '.value'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $script, "the script must not use {$forbidden}");
        }
    }

    public function test_coverage_and_the_browser_check_know_whether_the_script_is_expected(): void
    {
        $coverage = fn (): array => app(StoreCoverage::class)->forStores(collect([$this->store->refresh()]))[$this->store->id]['coverage']['checkout_script'];
        $key = $this->integration('key');
        $this->assertSame('needs_app', $coverage()['state']);

        $key->forceFill(['status' => Integration::STATUS_REVOKED])->save();
        $app = $this->integration('app');
        $scenario = $this->scenario(CheckScenario::ACCESS_STOP_BEFORE_SIGN_IN);
        $this->assertSame('check_does_not_reach_payment', $coverage()['state']);
        $snapshot = app(ScenarioDefinition::class)->snapshot($scenario, $this->store);
        $this->assertFalse($snapshot['expect_checkout_script']);

        $scenario->forceFill(['checkout_access' => CheckScenario::ACCESS_GUEST])->save();
        Cache::flush();
        $this->assertSame('waiting', $coverage()['state']);
        $snapshot = app(ScenarioDefinition::class)->snapshot($scenario->refresh(), $this->store);
        $this->assertTrue($snapshot['expect_checkout_script']);
        $this->assertSame([app(ScenarioDefinition::class)->origin((string) config('app.url'))], $snapshot['network_policy']['quiet_origins']);

        $this->counts($app, ['script_loaded' => 1]);
        $this->assertSame('observing', $coverage()['state']);

        $run = CheckRun::query()->create(['tenant_id' => $this->tenant->id, 'store_id' => $this->store->id, 'scenario_id' => $scenario->id, 'scenario_version' => 1, 'trigger' => 'manual', 'dedupe_key' => 'm:1', 'status' => CheckRun::STATUS_PASSED, 'config_snapshot' => $snapshot, 'scheduled_at' => now(), 'next_attempt_at' => now(), 'finished_at' => now(), 'created_at' => now()]);
        $worker = BrowserWorker::query()->create(['name' => 'w', 'token_hash' => str_repeat('1', 64), 'status' => 'active', 'created_at' => now()]);
        $attempt = CheckAttempt::query()->create(['tenant_id' => $this->tenant->id, 'store_id' => $this->store->id, 'run_id' => $run->id, 'attempt_number' => 1, 'worker_id' => $worker->id, 'fencing_token' => 1, 'lease_token_hash' => str_repeat('0', 64), 'lease_until' => now(), 'absolute_deadline_at' => now(), 'status' => 'passed', 'browser_version' => 'x', 'location' => 'x', 'started_at' => now(), 'finished_at' => now()]);
        CheckStep::query()->create(['tenant_id' => $this->tenant->id, 'store_id' => $this->store->id, 'attempt_id' => $attempt->id, 'step_index' => 5, 'step_code' => 'payment_form', 'status' => 'passed', 'started_at' => now(), 'finished_at' => now(), 'assertions' => [['code' => 'payment_method_available', 'passed' => true], ['code' => 'checkout_script_present', 'passed' => false, 'detail_code' => 'script_missing']], 'network_summary' => []]);
        $this->assertSame('not_seen_by_check', $coverage()['state']);
    }

    private function integration(string $connection, ?Store $store = null): Integration
    {
        $store ??= $this->store;
        $integration = Integration::query()->create([
            'tenant_id' => $store->tenant_id, 'store_id' => $store->id, 'provider' => ShopwareConnector::PROVIDER, 'mode' => 'live',
            'source_authority' => Integration::SOURCE_STORE_REPORTED, 'status' => Integration::STATUS_ACTIVE,
            'capabilities' => ['orders' => 'read', 'connection' => $connection], 'api_version' => 'admin-api', 'connector_version' => 'test', 'health' => [],
        ]);
        app(ShopwareConnector::class)->storeSecretJson($integration, IntegrationCredential::KIND_SHOPWARE_INTEGRATION, ['access_key' => 'SWIA'.Str::random(22), 'secret' => Str::random(40), 'api_url' => 'https://shop.registered.test'], Str::random(40));

        return $integration;
    }

    private function attempt(Integration $integration, string $ref): void
    {
        ObservedPaymentAttempt::query()->create(['tenant_id' => $this->tenant->id, 'store_id' => $this->store->id, 'integration_id' => $integration->id, 'transaction_ref' => str_repeat($ref, 32), 'order_ref' => str_repeat($ref, 32), 'payment_method' => 'payment_prepayment', 'attempted_at' => now(), 'outcome' => 'on_hold', 'outcome_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function otherStore(): Store
    {
        return Store::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Other', 'base_url' => 'https://other.example.test', 'timezone' => 'Europe/Berlin', 'default_currency' => 'EUR', 'platform' => 'shopware']);
    }

    private function scenario(string $access): CheckScenario
    {
        return CheckScenario::query()->create([
            'tenant_id' => $this->tenant->id, 'store_id' => $this->store->id, 'name' => 'Payment form', 'mode' => CheckScenario::MODE_PAYMENT_FORM,
            'version' => 1, 'enabled' => true, 'adapter_version' => ScenarioDefinition::SHOPWARE_ADAPTER_VERSION, 'checkout_access' => $access,
            'definition' => ['product_url' => self::SHOP.'/detail/1'], 'interval_seconds' => 900, 'next_due_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function counts(Integration $integration, array $counts): void
    {
        Carbon::setTestNow(now()->addMinutes(5));
        CheckoutObservationWindow::query()->create([
            'tenant_id' => $integration->tenant_id, 'store_id' => $integration->store_id, 'integration_id' => $integration->id,
            'window_start' => Carbon::createFromTimestampUTC(now()->getTimestamp() - now()->getTimestamp() % 300), ...$counts, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function rendered(Incident $incident): RenderedNotification
    {
        $content = app(IncidentNotificationContentBuilder::class)->forIncident($incident, $this->store, 'incident_opened', NotificationPreferences::fromArray([], 'ru', 'Europe/Berlin'));

        return app(NotificationRenderer::class)->render($content);
    }
}
