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
use App\Support\Providers\Shopware\ShopwareClient;
use App\Support\Providers\Shopware\ShopwareConnector;
use App\Support\Providers\Shopware\ShopwareRequestFailed;
use App\Support\Providers\Shopware\ShopwareSync;
use App\Support\Stores\StoreCoverage;
use Illuminate\Console\Scheduling\Schedule;
use App\Support\Network\DnsClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Feature\Stores\FakeDnsClient;
use Tests\TestCase;
use ZipArchive;

class CheckoutObservationTest extends TestCase
{
    use RefreshDatabase;

    private const SHOP = 'https://shop.example.test';

    private Tenant $tenant;

    /** @var array<string, array<string, mixed>> reports the app keeps in the shop */
    private array $reports = [];

    /** @var list<list<array<string, mixed>>> */
    private array $syncs = [];

    private int $failDeletes = 0;

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

        config(['watchdog.shopware.request_pause_ms' => 0, 'watchdog.shopware.dev_endpoint' => '']);
        $dns = new FakeDnsClient;
        $dns->ips['shop.registered.test'] = ['93.184.216.34'];
        $dns->ips['shop.example.test'] = ['93.184.216.34'];
        $this->app->instance(DnsClient::class, $dns);
        Http::fake(function (HttpRequest $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $body = json_decode($request->body(), true) ?? [];

            return match ($path) {
                '/api/oauth/token' => Http::response(['expires_in' => 600, 'access_token' => 'token']),
                '/api/search/order' => Http::response(['total' => 0, 'data' => []]),
                ShopwareClient::REPORT_SEARCH_PATH => Http::response(['total' => count($this->reports), 'data' => array_slice(array_values($this->reports), 0, (int) ($body['limit'] ?? 100))]),
                ShopwareClient::SYNC_PATH => $this->applySync($body),
                default => Http::response(['errors' => [['code' => 'FRAMEWORK__ROUTE_NOT_FOUND']]], 404),
            };
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_sync_keeps_the_app_setting_and_counts_each_report_of_the_shop_once(): void
    {
        $app = $this->integration('app');
        $this->scenario(CheckScenario::ACCESS_GUEST);
        $this->reports = [
            $this->report('confirm', 'rendered', 0, '2026-10-14T11:57:10.000+00:00'),
            $this->report('confirm', 'rendered_noscript', 0, '2026-10-14T11:57:11.000+00:00'),
            $this->report('confirm', 'script_loaded,form_ready,submit_clicked,credit_card_number,script_loaded', 500, '2026-10-14T11:57:12.000+00:00'),
            $this->report('finish', 'rendered', 0, '2026-10-14T11:58:00.000+00:00'),
            $this->report('evil', 'submit_clicked', 0, '2026-10-14T11:58:01.000+00:00'),
            $this->report('confirm', 'submit_clicked', 0, '2026-10-14T11:50:00.000+00:00'),
        ];
        $this->failDeletes = 1;

        app(ShopwareSync::class)->run($app);
        $this->assertSame(['observe' => true], $this->settingWrites()[0]);
        $this->assertSame('shopware_unavailable', $app->refresh()->health['observation']['last_error']['code'], 'a failed delete keeps the reports in the shop');
        $this->assertCount(6, $this->reports);

        Carbon::setTestNow(now()->addMinutes(5));
        app(ShopwareSync::class)->run($app);
        $this->assertSame([], $this->reports, 'the second run deletes them');
        $this->assertNull($app->refresh()->health['observation']['last_error']);
        $this->assertSame(1, count($this->settingWrites()), 'the setting is written again only when it changes or after an hour');

        $windows = CheckoutObservationWindow::query()->orderBy('window_start')->get();
        $this->assertSame(['2026-10-14 11:50:00', '2026-10-14 11:55:00'], $windows->map(fn ($w) => $w->window_start->format('Y-m-d H:i:s'))->all());
        $this->assertSame(1, $windows[0]->submit_clicked);
        $this->assertSame(
            ['confirm_rendered' => 1, 'confirm_rendered_noscript' => 1, 'finish_rendered' => 1, 'script_loaded' => 1, 'form_ready' => 1, 'returned_with_error' => 0, 'submit_clicked' => 1, 'submit_stalled' => 0, 'finish_reached' => 0, 'js_errors' => 20],
            array_intersect_key($windows[1]->toArray(), array_flip(CheckoutObservationWindow::COUNTERS)),
            'counted once although the shop returned the rows twice; unknown pages and stages ignored; errors capped',
        );
        $this->assertSame(['id', 'tenant_id', 'store_id', 'integration_id', 'window_start', ...CheckoutObservationWindow::COUNTERS, 'created_at', 'updated_at', 'source_revision', 'event_id'], array_keys((array) \DB::table('checkout_observation_windows')->first()), 'no column for an address, agent or visitor id');
        $this->assertSame([ShopwareClient::SETTING_ENTITY, ShopwareClient::REPORT_ENTITY], collect($this->syncs)->flatten(1)->pluck('entity')->unique()->values()->all(), 'the service writes only the app\'s own entities');
    }

    public function test_reports_of_a_store_that_is_not_observed_are_deleted_without_counting(): void
    {
        $app = $this->integration('app');
        $this->scenario(CheckScenario::ACCESS_STOP_BEFORE_SIGN_IN);
        $this->reports = [$this->report('confirm', 'rendered', 0, '2026-10-14T11:57:10.000+00:00')];

        app(ShopwareSync::class)->run($app);

        $this->assertSame([['observe' => false]], $this->settingWrites());
        $this->assertSame([], $this->reports);
        $this->assertSame(0, CheckoutObservationWindow::query()->count());
        $this->assertSame(1, $app->refresh()->health['observation']['dropped']);

        $key = $this->integration('key', $this->otherStore());
        $this->syncs = [];
        app(ShopwareSync::class)->run($key);
        $this->assertSame([], $this->syncs, 'a connection without the app is never asked for reports');
    }

    public function test_the_client_writes_nothing_but_the_apps_own_entities(): void
    {
        foreach ([
            ['action' => 'delete', 'entity' => 'order', 'payload' => [['id' => str_repeat('a', 32)]]],
            ['action' => 'upsert', 'entity' => ShopwareClient::REPORT_ENTITY, 'payload' => [['id' => str_repeat('a', 32)]]],
            ['action' => 'delete', 'entity' => ShopwareClient::SETTING_ENTITY, 'payload' => [['id' => str_repeat('a', 32)]]],
        ] as $operation) {
            try {
                app(ShopwareClient::class)->syncOwnEntities(self::SHOP, 'token', [$operation]);
                $this->fail('refused');
            } catch (ShopwareRequestFailed $failure) {
                $this->assertSame('shopware_endpoint_not_allowed', $failure->reasonCode);
            }
        }

        $this->assertSame([], $this->syncs);
        $this->assertFalse(ShopwareClient::allowed('POST', '/api/search/customer'));
        $this->assertTrue(ShopwareClient::allowed('POST', ShopwareClient::REPORT_SEARCH_PATH));
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

    public function test_the_app_archive_has_observation_without_checkout_render_hooks(): void
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

        $endpoint = (string) $zip->getFromName('BusinessWatchdog/Resources/scripts/storefront-bw-observe/observe.twig');
        $entities = (string) $zip->getFromName('BusinessWatchdog/Resources/entities.xml');
        $script = (string) $zip->getFromName('BusinessWatchdog/Resources/app/storefront/dist/storefront/js/business-watchdog/business-watchdog.js');
        $manifest = (string) $zip->getFromName('BusinessWatchdog/manifest.xml');
        $zip->close();
        @unlink($path);

        $this->assertSame([], array_values(array_filter($names, fn (string $name): bool => str_contains($name, '/Resources/views/') || str_contains($name, 'page-loaded/'))));
        $this->assertStringContainsString('<version>'.ShopwareApp::APP_VERSION.'</version>', $manifest);
        $this->assertStringContainsString("new URL('/storefront/script/bw-observe'", $script);
        $this->assertStringContainsString('custom_entity_bw_checkout_report', $entities);
        $this->assertStringContainsString("services.writer.upsert('custom_entity_bw_checkout_report'", $endpoint);
        $this->assertStringContainsString('response.cache.disable()', $endpoint);
        foreach ([$script, $endpoint] as $file) {
            $this->assertStringNotContainsString('/api/v1/', $file, 'the shopper\'s browser never contacts the service');
            $this->assertStringNotContainsString((string) parse_url((string) config('app.url'), PHP_URL_HOST), $file);
        }
        $this->assertDoesNotMatchRegularExpression('/<(create|update|delete)>/', $manifest, 'the app gets its own entities without extra permissions');
        foreach (['localStorage', 'sessionStorage', 'document.cookie', '.value', 'fetch('] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $script, "the script must not use {$forbidden}");
        }
    }

    public function test_shopware_app_archive_is_not_issued_without_exact_release_approval(): void
    {
        config()->set('watchdog.connector_release_gate', true);
        $request = fn () => $this->actingAs($this->user)->withSession(['active_tenant_id' => $this->tenant->id])
            ->postJson("/api/v1/stores/{$this->store->id}/integrations/shopware-app", ['state_change_risk_acknowledged' => true]);

        $request()->assertStatus(409)->assertJsonPath('code', 'shopware_app_release_not_approved');

        $app = app(ShopwareApp::class);
        config()->set('watchdog.shopware.app_approved_sha256', $app->releaseFingerprint());
        $request()->assertStatus(409)->assertJsonPath('code', 'shopware_app_release_not_approved');
        config()->set('watchdog.shopware.app_approved_store_ids', (string) $this->store->id);
        $request()->assertOk();

        config()->set('watchdog.shopware.app_approved_sha256', str_repeat('0', 64));
        $request()->assertStatus(409)->assertJsonPath('code', 'shopware_app_release_not_approved');
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
        $this->assertSame(['/storefront/script/bw-observe'], $snapshot['network_policy']['quiet_paths']);

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

    /**
     * @return array<string, mixed>
     */
    private function report(string $page, string $stages, int $errors, string $createdAt): array
    {
        return ['id' => bin2hex(random_bytes(16)), 'page' => $page, 'stages' => $stages, 'errors' => $errors, 'createdAt' => $createdAt];
    }

    /**
     * @param  list<array<string, mixed>>  $operations
     */
    private function applySync(array $operations): \GuzzleHttp\Promise\PromiseInterface
    {
        $this->syncs[] = $operations;

        foreach ($operations as $operation) {
            if ($operation['action'] === 'delete') {
                if ($this->failDeletes-- > 0) {
                    return Http::response(['errors' => [['code' => '0']]], 500);
                }

                $this->reports = array_values(array_filter($this->reports, fn (array $row): bool => ! in_array($row['id'], array_column($operation['payload'], 'id'), true)));
            }
        }

        return Http::response(['success' => true]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function settingWrites(): array
    {
        return collect($this->syncs)->flatten(1)->where('entity', ShopwareClient::SETTING_ENTITY)->map(fn (array $op): array => ['observe' => $op['payload'][0]['observe']])->values()->all();
    }

    private function rendered(Incident $incident): RenderedNotification
    {
        $content = app(IncidentNotificationContentBuilder::class)->forIncident($incident, $this->store, 'incident_opened', NotificationPreferences::fromArray([], 'ru', 'Europe/Berlin'));

        return app(NotificationRenderer::class)->render($content);
    }
}
