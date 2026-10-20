<?php

namespace Tests\Feature\Providers;

use App\Http\Dto\Integrations\IntegrationDto;
use App\Models\AuditLog;
use App\Models\Integration;
use App\Models\IntegrationCredential;
use App\Models\Membership;
use App\Models\Order;
use App\Models\Store;
use App\Models\StoreVerification;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Network\DnsClient;
use App\Support\Outbox\DomainOutboxDispatcher;
use App\Support\Providers\Shopware\ShopwareApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Stores\FakeDnsClient;
use Tests\TestCase;
use ZipArchive;

class ShopwareAppTest extends TestCase
{
    use RefreshDatabase;

    private const SHOP_URL = 'https://shop.example.test';

    private const SHOP_ID = 'aBcDeFgHiJkLmNoP';

    private const API_KEY = 'SWIAAPPKEYFORWATCHDOGTEST';

    private const API_SECRET = 'AppSecretAccessKeyForWatchdogTests1234567890abcdef';

    private User $user;

    private Tenant $tenant;

    private Store $store;

    /** @var array<string, array<string, mixed>> */
    private array $orders = [];

    /** @var list<array{path: string, body: array<string, mixed>}> */
    private array $requests = [];

    private bool $appActive = true;

    protected function setUp(): void
    {
        parent::setUp();

        config(['watchdog.shopware.request_pause_ms' => 0, 'watchdog.shopware.dev_endpoint' => '']);
        $dns = new FakeDnsClient;
        $dns->ips['shop.example.test'] = ['93.184.216.34'];
        $this->app->instance(DnsClient::class, $dns);
        $this->user = User::query()->create(['name' => 'Owner', 'email' => 'owner@example.test', 'password_hash' => Hash::make('very-secure-password'), 'locale' => 'de']);
        $this->tenant = Tenant::query()->create(['name' => 'Kaffee', 'timezone' => 'Europe/Berlin']);
        Membership::query()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id, 'role' => 'owner']);
        $this->store = Store::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Shop', 'base_url' => self::SHOP_URL, 'timezone' => 'Europe/Berlin', 'default_currency' => 'EUR', 'platform' => 'shopware']);

        Http::fake(function (HttpRequest $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $body = json_decode($request->body(), true) ?? [];
            $this->requests[] = ['path' => $request->method().' '.$path, 'body' => $body];

            if ($path === '/api/oauth/token') {
                return $this->appActive && ($body['client_id'] ?? null) === self::API_KEY && ($body['client_secret'] ?? null) === self::API_SECRET
                    ? Http::response(['expires_in' => 600, 'access_token' => 'app-token'])
                    : Http::response(['errors' => [['code' => '6']]], 401);
            }

            if ($path === '/api/search/order') {
                return Http::response(['total' => count($this->orders), 'data' => array_values($this->orders)]);
            }

            return Http::response(['errors' => [['code' => 'FRAMEWORK__ROUTE_NOT_FOUND']]], 404);
        });
    }

    public function test_the_app_archive_names_only_read_permissions_and_a_registration_for_this_store(): void
    {
        [$integration, $manifest] = $this->package();

        $this->assertSame(Integration::STATUS_PENDING, $integration->status);
        $this->assertSame('app', $integration->capabilities['connection']);
        $this->assertStringContainsString('<name>BusinessWatchdog</name>', $manifest);
        $this->assertStringContainsString('<label lang="de-DE">Business Watchdog</label>', $manifest, 'bin/console app:install refuses an app without a de-DE label');
        $this->assertStringContainsString('/api/v1/shopware/app/'.$integration->id.'/register', $manifest);
        $this->assertStringContainsString('<read>order</read>', $manifest);
        $this->assertDoesNotMatchRegularExpression('/<(create|update|delete)>/', $manifest);
        $this->assertStringNotContainsString('customer', $manifest);
        $this->assertStringContainsString('event="state_enter.order_transaction.state.paid"', $manifest);
        $this->assertStringContainsString('event="state_enter.order_transaction.state.refunded_partially"', $manifest);
        $this->assertStringContainsString('event="checkout.order.placed"', $manifest);

        [$second] = $this->package();
        $this->assertSame(Integration::STATUS_REVOKED, $integration->refresh()->status, 'a new archive replaces the pending one');
        $this->assertSame(Integration::STATUS_PENDING, $second->status);
    }

    public function test_shopware_registers_confirms_and_the_app_becomes_the_order_source(): void
    {
        [$integration, $manifest] = $this->package();
        $secret = $this->manifestSecret($manifest);

        $this->register($integration, 'wrong-secret')->assertStatus(401)->assertJsonPath('error', 'shopware_app_signature_invalid');
        $registration = $this->register($integration, $secret)->assertOk();
        $this->assertSame(hash_hmac('sha256', self::SHOP_ID.self::SHOP_URL.'BusinessWatchdog', $secret), $registration->json('proof'));
        $shopSecret = $registration->json('secret');
        $this->assertStringEndsWith('/api/v1/shopware/app/'.$integration->id.'/confirm', $registration->json('confirmation_url'));

        $confirmation = json_encode(['apiKey' => self::API_KEY, 'secretKey' => self::API_SECRET, 'timestamp' => (string) time(), 'shopUrl' => self::SHOP_URL, 'shopId' => self::SHOP_ID], JSON_THROW_ON_ERROR);
        $this->signedPost($integration, 'confirm', $confirmation, 'bad')->assertStatus(401);
        $this->signedPost($integration, 'confirm', $confirmation, $shopSecret)->assertOk();

        $integration->refresh();
        $this->assertSame([Integration::STATUS_ACTIVE, self::SHOP_ID], [$integration->status, $integration->external_account_id]);
        $this->assertStringNotContainsString(self::API_SECRET, (string) IntegrationCredential::query()->where('kind', IntegrationCredential::KIND_SHOPWARE_INTEGRATION)->value('ciphertext'));
        $this->assertSame(StoreVerification::METHOD_API_KEY, StoreVerification::query()->where('store_id', $this->store->id)->sole()->method);

        $this->orders['a1'] = $this->order('0123456789abcdef0123456789abcdef', 'paid');
        $this->requests = [];
        $this->webhook($integration, $shopSecret, 'state_enter.order_transaction.state.paid', ['order' => ['id' => '0123456789abcdef0123456789abcdef', 'orderCustomer' => ['email' => 'buyer@example.test']]])->assertOk()->assertJsonPath('result', 'ok');
        $this->drain();

        $this->assertSame(['id', ['0123456789abcdef0123456789abcdef']], [collect($this->requests)->firstWhere('path', 'POST /api/search/order')['body']['filter'][0]['queries'][0]['field'], collect($this->requests)->firstWhere('path', 'POST /api/search/order')['body']['filter'][0]['queries'][0]['value']]);
        $this->assertNotNull(Order::query()->where('external_id', '0123456789abcdef0123456789abcdef')->value('paid_marked_at'), 'the webhook made the order arrive without waiting for the delta');

        $this->webhook($integration, $shopSecret, 'app.deactivated', [])->assertOk();
        $this->assertSame(Integration::STATUS_DEGRADED, $integration->refresh()->status);
        $this->webhook($integration, $shopSecret, 'app.activated', [])->assertOk();
        $this->assertSame(Integration::STATUS_ACTIVE, $integration->refresh()->status);
        $this->webhook($integration, $shopSecret, 'app.deleted', [])->assertOk();
        $this->assertSame(Integration::STATUS_REVOKED, $integration->refresh()->status);
        $this->assertSame(0, IntegrationCredential::query()->where('integration_id', $integration->id)->where('status', IntegrationCredential::STATUS_ACTIVE)->count());
    }

    public function test_webhooks_need_the_shop_signature_and_the_registered_shop(): void
    {
        [$integration, $manifest] = $this->package();
        $shopSecret = $this->register($integration, $this->manifestSecret($manifest))->json('secret');
        $this->signedPost($integration, 'confirm', json_encode(['apiKey' => self::API_KEY, 'secretKey' => self::API_SECRET, 'timestamp' => (string) time(), 'shopUrl' => self::SHOP_URL, 'shopId' => self::SHOP_ID], JSON_THROW_ON_ERROR), $shopSecret)->assertOk();

        $this->webhook($integration, 'not-the-secret', 'order.written', [])->assertStatus(401);
        $body = json_encode(['data' => ['payload' => [], 'event' => 'app.deleted'], 'source' => ['url' => self::SHOP_URL, 'shopId' => 'someOtherShop1'], 'timestamp' => time()], JSON_THROW_ON_ERROR);
        $this->signedPost($integration, 'webhook', $body, $shopSecret)->assertStatus(422);
        $this->assertSame(Integration::STATUS_ACTIVE, $integration->refresh()->status);
    }

    public function test_a_key_of_a_not_yet_active_app_waits_for_app_activated(): void
    {
        [$integration, $manifest] = $this->package();
        $shopSecret = $this->register($integration, $this->manifestSecret($manifest))->json('secret');
        $this->appActive = false;
        $this->signedPost($integration, 'confirm', json_encode(['apiKey' => self::API_KEY, 'secretKey' => self::API_SECRET, 'timestamp' => (string) time(), 'shopUrl' => self::SHOP_URL, 'shopId' => self::SHOP_ID], JSON_THROW_ON_ERROR), $shopSecret)->assertOk();

        $this->assertSame([Integration::STATUS_PENDING, 'shopware_key_rejected'], [$integration->refresh()->status, $integration->health['app_check_error']]);
        $this->assertNull($this->store->refresh()->verified_at);

        $this->appActive = true;
        $this->webhook($integration, $shopSecret, 'app.activated', [])->assertOk()->assertJsonPath('result', 'active');
        $this->assertSame(Integration::STATUS_ACTIVE, $integration->refresh()->status);
        $this->assertNotNull($this->store->refresh()->verified_at);
    }

    public function test_a_connected_app_gets_an_update_archive_for_the_same_integration_and_its_version_is_tracked(): void
    {
        $owner = fn () => $this->actingAs($this->user)->withSession(['active_tenant_id' => $this->tenant->id]);
        $owner()->postJson("/api/v1/stores/{$this->store->id}/integrations/shopware-app/update")->assertStatus(404)->assertJsonPath('code', 'shopware_app_not_connected');

        [$integration, $manifest] = $this->package();
        $secret = $this->manifestSecret($manifest);
        $shopSecret = $this->register($integration, $secret)->assertOk()->json('secret');
        $confirmation = json_encode(['apiKey' => self::API_KEY, 'secretKey' => self::API_SECRET, 'timestamp' => (string) time(), 'shopUrl' => self::SHOP_URL, 'shopId' => self::SHOP_ID], JSON_THROW_ON_ERROR);
        $this->signedPost($integration, 'confirm', $confirmation, $shopSecret)->assertOk();
        $this->webhook($integration, $shopSecret, 'app.activated', [], '1.0.0')->assertOk();
        $this->assertSame(['1.0.0', true], [$this->appUpdate($integration)['installed_version'], $this->appUpdate($integration)['available']]);

        $response = $owner()->postJson("/api/v1/stores/{$this->store->id}/integrations/shopware-app/update")->assertOk()->assertHeader('Content-Type', 'application/zip')->assertHeader('X-App-Version', ShopwareApp::APP_VERSION);
        $this->assertSame($integration->id, $response->headers->get('X-Integration-Id'));
        $path = tempnam(sys_get_temp_dir(), 'bw-test-app');
        file_put_contents($path, $response->getContent());
        $zip = new ZipArchive;
        $zip->open($path);
        $update = (string) $zip->getFromName('BusinessWatchdog/manifest.xml');
        $hasScript = $zip->locateName('BusinessWatchdog/Resources/app/storefront/dist/storefront/js/business-watchdog/business-watchdog.js') !== false;
        $zip->close();
        @unlink($path);
        $this->assertSame($secret, $this->manifestSecret($update), 'the same manifest secret, so Shopware updates the installed app');
        $this->assertStringContainsString('<version>'.ShopwareApp::APP_VERSION.'</version>', $update);
        $this->assertStringContainsString('/api/v1/shopware/app/'.$integration->id.'/register', $update);
        $this->assertStringContainsString('event="app.updated"', $update);
        $this->assertTrue($hasScript);
        $this->assertSame(1, Integration::query()->where('store_id', $this->store->id)->count(), 'nothing new is created');
        $this->assertSame(Integration::STATUS_ACTIVE, $integration->refresh()->status);
        $this->assertTrue(AuditLog::query()->where('action', AuditLog::ACTION_INTEGRATION_PROVIDER_CONNECTED)->get()->contains(fn (AuditLog $log): bool => ($log->changes['stage'] ?? null) === 'update_package_created'));

        $this->webhook($integration, $shopSecret, 'app.updated', [], ShopwareApp::APP_VERSION)->assertOk()->assertJsonPath('result', 'updated');
        $this->assertSame([ShopwareApp::APP_VERSION, false], [$this->appUpdate($integration)['installed_version'], $this->appUpdate($integration)['available']]);
        $this->assertSame('shopware-app-'.ShopwareApp::APP_VERSION, $integration->refresh()->connector_version);
    }

    /**
     * @return array<string, mixed>
     */
    private function appUpdate(Integration $integration): array
    {
        return app(IntegrationDto::class)->toArray($integration->refresh())['app_update'];
    }

    public function test_an_app_package_is_refused_while_another_order_source_is_connected(): void
    {
        Integration::query()->create([
            'tenant_id' => $this->tenant->id, 'store_id' => $this->store->id, 'provider' => 'woocommerce_api', 'mode' => 'live',
            'source_authority' => Integration::SOURCE_STORE_REPORTED, 'status' => Integration::STATUS_ACTIVE, 'capabilities' => [], 'connector_version' => '1', 'health' => [],
        ]);

        $this->actingAs($this->user)->withSession(['active_tenant_id' => $this->tenant->id])
            ->postJson("/api/v1/stores/{$this->store->id}/integrations/shopware-app", ['state_change_risk_acknowledged' => false])->assertStatus(422)->assertJsonPath('code', 'shopware_state_change_risk_not_acknowledged');
        $this->actingAs($this->user)->withSession(['active_tenant_id' => $this->tenant->id])
            ->postJson("/api/v1/stores/{$this->store->id}/integrations/shopware-app", ['state_change_risk_acknowledged' => true])->assertStatus(409)->assertJsonPath('code', 'order_source_already_connected');
    }

    /**
     * @return array{0: Integration, 1: string}
     */
    private function package(): array
    {
        $response = $this->actingAs($this->user)->withSession(['active_tenant_id' => $this->tenant->id])
            ->postJson("/api/v1/stores/{$this->store->id}/integrations/shopware-app", ['state_change_risk_acknowledged' => true])->assertOk()->assertHeader('Content-Type', 'application/zip');
        $path = tempnam(sys_get_temp_dir(), 'bw-test-app');
        file_put_contents($path, $response->getContent());
        $zip = new ZipArchive;
        $zip->open($path);
        $manifest = (string) $zip->getFromName('BusinessWatchdog/manifest.xml');
        $zip->close();
        @unlink($path);

        return [Integration::query()->findOrFail($response->headers->get('X-Integration-Id')), $manifest];
    }

    private function manifestSecret(string $manifest): string
    {
        preg_match('#<secret>([^<]+)</secret>#', $manifest, $match);

        return $match[1];
    }

    private function register(Integration $integration, string $secret): TestResponse
    {
        $query = http_build_query(['shop-id' => self::SHOP_ID, 'shop-url' => self::SHOP_URL, 'timestamp' => (string) time()]);

        return $this->call('GET', '/api/v1/shopware/app/'.$integration->id.'/register?'.$query, [], [], [], ['HTTP_SHOPWARE_APP_SIGNATURE' => hash_hmac('sha256', $query, $secret), 'HTTP_ACCEPT' => 'application/json']);
    }

    private function signedPost(Integration $integration, string $action, string $body, string $secret): TestResponse
    {
        return $this->call('POST', '/api/v1/shopware/app/'.$integration->id.'/'.$action, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_SHOPWARE_SHOP_SIGNATURE' => hash_hmac('sha256', $body, $secret)], $body);
    }

    /**
     * @param  array<int|string, mixed>  $payload
     */
    private function webhook(Integration $integration, string $secret, string $event, array $payload, string $version = '1.0.0'): TestResponse
    {
        return $this->signedPost($integration, 'webhook', json_encode(['data' => ['payload' => $payload, 'event' => $event], 'source' => ['url' => self::SHOP_URL, 'shopId' => self::SHOP_ID, 'appVersion' => $version], 'timestamp' => time()], JSON_THROW_ON_ERROR), $secret);
    }

    /**
     * @return array<string, mixed>
     */
    private function order(string $id, string $state): array
    {
        return [
            'id' => $id, 'orderNumber' => '10001', 'amountTotal' => 23.41, 'currency' => ['isoCode' => 'EUR'], 'stateMachineState' => ['technicalName' => 'open'],
            'createdAt' => now()->subHour()->format('Y-m-d\TH:i:s.vP'), 'updatedAt' => null,
            'transactions' => [['id' => 'fedcba9876543210fedcba9876543210', 'amount' => ['totalPrice' => 23.41], 'stateMachineState' => ['technicalName' => $state], 'paymentMethod' => ['technicalName' => 'swag_paypal_paypal'],
                'customFields' => ['swag_paypal_resource_id' => '7HA89644RL323394X', 'swag_paypal_is_sandbox' => true], 'createdAt' => now()->subHour()->format('Y-m-d\TH:i:s.vP'), 'updatedAt' => now()->format('Y-m-d\TH:i:s.vP')]],
        ];
    }

    private function drain(): void
    {
        for ($i = 0; $i < 10; $i++) {
            app(DomainOutboxDispatcher::class)->dispatchDue(limit: 100, leaseSeconds: 60);
        }
    }
}
