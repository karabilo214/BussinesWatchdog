<?php

namespace Tests\Feature\Providers;

use App\Models\Integration;
use App\Models\Membership;
use App\Models\PairingCode;
use App\Models\ShopwareAppInstallation;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Network\DnsClient;
use App\Support\Providers\Shopware\ShopwareApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Stores\FakeDnsClient;
use Tests\TestCase;
use ZipArchive;

class ShopwareAppUniversalTest extends TestCase
{
    use RefreshDatabase;

    private const SHOP_URL = 'https://shop.example.test';

    private const SHOP_ID = 'aBcDeFgHiJkLmNoP';

    private const API_KEY = 'SWIAAPPKEYFORWATCHDOGTEST';

    private const API_SECRET = 'AppSecretAccessKeyForWatchdogTests1234567890abcdef';

    private User $user;

    private Tenant $tenant;

    private Store $store;

    private bool $appActive = true;

    protected function setUp(): void
    {
        parent::setUp();

        config(['watchdog.shopware.request_pause_ms' => 0, 'watchdog.shopware.dev_endpoint' => '']);
        $dns = new FakeDnsClient;
        $dns->ips['shop.example.test'] = ['93.184.216.34'];
        $dns->ips['other.example.test'] = ['93.184.216.35'];
        $this->app->instance(DnsClient::class, $dns);
        $this->user = User::query()->create(['name' => 'Owner', 'email' => 'owner@example.test', 'password_hash' => Hash::make('very-secure-password'), 'locale' => 'de']);
        $this->tenant = Tenant::query()->create(['name' => 'Kaffee', 'timezone' => 'Europe/Berlin']);
        Membership::query()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id, 'role' => 'owner']);
        $this->store = Store::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Kaffeerösterei', 'base_url' => 'https://www.shop.example.test', 'timezone' => 'Europe/Berlin', 'default_currency' => 'EUR', 'platform' => 'shopware']);

        Http::fake(function (HttpRequest $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $body = json_decode($request->body(), true) ?? [];

            if ($path === '/api/oauth/token') {
                return $this->appActive && ($body['client_id'] ?? null) === self::API_KEY ? Http::response(['expires_in' => 600, 'access_token' => 'app-token']) : Http::response(['errors' => [['code' => '6']]], 401);
            }

            return $path === '/api/search/order' ? Http::response(['total' => 0, 'data' => []]) : Http::response(['errors' => [['code' => 'FRAMEWORK__ROUTE_NOT_FOUND']]], 404);
        });
    }

    public function test_one_archive_for_everyone_binds_to_the_store_by_code_and_domain(): void
    {
        $manifest = $this->download();
        $this->assertStringContainsString('<registrationUrl>'.url('/api/v1/shopware/app/register').'</registrationUrl>', $manifest);
        $this->assertStringContainsString('<secret>'.ShopwareApp::appSecret().'</secret>', $manifest);
        $this->assertStringContainsString('<main-module source="'.url('/api/v1/shopware/app/connect').'"/>', $manifest);
        $this->assertStringContainsString('url="'.url('/api/v1/shopware/app/webhook').'"', $manifest);
        $this->assertSame($manifest, preg_replace('/<version>[^<]+<\/version>/', '<version>'.ShopwareApp::APP_VERSION.'</version>', $this->download()), 'the same archive for every request');

        $this->register('wrong')->assertStatus(401);
        $registration = $this->register(ShopwareApp::appSecret())->assertOk();
        $this->assertSame(hash_hmac('sha256', self::SHOP_ID.self::SHOP_URL.'BusinessWatchdog', ShopwareApp::appSecret()), $registration->json('proof'));
        $this->assertStringEndsWith('/api/v1/shopware/app/confirm', $registration->json('confirmation_url'));
        $shopSecret = $registration->json('secret');
        $this->confirm($shopSecret)->assertOk();
        $this->assertSame(ShopwareAppInstallation::STATUS_CONFIRMED, ShopwareAppInstallation::query()->sole()->status);
        $this->assertSame(0, Integration::query()->count(), 'nothing is read before the code');

        $this->get('/api/v1/shopware/app/connect?'.http_build_query(['shop-id' => self::SHOP_ID, 'timestamp' => (string) time()]).'&shopware-shop-signature=bad')->assertStatus(401);
        $page = $this->module($shopSecret)->assertOk()->assertSee('Verbindungscode');
        $this->assertStringContainsString('frame-ancestors '.self::SHOP_URL, (string) $page->headers->get('Content-Security-Policy'));

        $this->connect($shopSecret, 'bwpc_unknown')->assertOk()->assertSee('Der Code wurde nicht gefunden');
        $other = Store::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Other', 'base_url' => 'https://other.example.test', 'timezone' => 'Europe/Berlin', 'default_currency' => 'EUR', 'platform' => 'shopware']);
        $this->connect($shopSecret, $this->code($other))->assertSee('stimmt nicht mit der Shop-Adresse');
        $this->assertSame(0, Integration::query()->count());

        $code = $this->code($this->store);
        $this->connect($shopSecret, $code)->assertOk()->assertSee('Verbunden mit dem Shop „Kaffeerösterei“');

        $installation = ShopwareAppInstallation::query()->sole();
        $integration = Integration::query()->sole();
        $this->assertSame([ShopwareAppInstallation::STATUS_BOUND, $integration->id], [$installation->status, $installation->integration_id]);
        $this->assertSame([Integration::STATUS_ACTIVE, $this->store->id, 'app', self::SHOP_ID], [$integration->status, $integration->store_id, $integration->capabilities['connection'], $integration->external_account_id]);
        $this->assertNotNull(PairingCode::query()->where('code_hash', hash('sha256', $code))->value('consumed_at'));
        $this->connect($shopSecret, $this->code($this->store))->assertSee('Diese Installation ist mit dem Shop');

        $this->webhook($shopSecret, 'app.updated', '1.4.1')->assertOk()->assertJsonPath('result', 'updated');
        $this->assertSame('1.4.1', $integration->refresh()->health['app_version']);
        $this->webhook($shopSecret, 'app.deleted')->assertOk();
        $this->assertSame(Integration::STATUS_REVOKED, $integration->refresh()->status);
        $this->assertSame(0, ShopwareAppInstallation::query()->count());
    }

    public function test_an_installation_whose_app_key_does_not_work_is_not_bound_and_old_unbound_ones_are_purged(): void
    {
        $shopSecret = $this->register(ShopwareApp::appSecret())->json('secret');
        $this->connect($shopSecret, $this->code($this->store))->assertSee('noch nicht vollständig registriert');

        $this->confirm($shopSecret)->assertOk();
        $this->appActive = false;
        $this->connect($shopSecret, $this->code($this->store))->assertSee('nicht bei diesem Shop anmelden');
        $this->assertSame(ShopwareAppInstallation::STATUS_CONFIRMED, ShopwareAppInstallation::query()->sole()->status);
        $this->assertSame(Integration::STATUS_REVOKED, Integration::query()->sole()->status);

        $this->webhook($shopSecret, 'app.activated')->assertOk()->assertJsonPath('result', 'not_bound');

        Carbon::setTestNow(now()->addDays(8));
        $this->artisan('shopware:app-installations-purge')->assertSuccessful();
        $this->assertSame(0, ShopwareAppInstallation::query()->count());
    }

    private function download(): string
    {
        $response = $this->actingAs($this->user)->withSession(['active_tenant_id' => $this->tenant->id])->get('/api/v1/downloads/shopware-app')->assertOk()->assertHeader('Content-Type', 'application/zip');
        $path = tempnam(sys_get_temp_dir(), 'bw-test-app');
        file_put_contents($path, $response->getContent());
        $zip = new ZipArchive;
        $zip->open($path);
        $manifest = (string) $zip->getFromName('BusinessWatchdog/manifest.xml');
        $zip->close();
        @unlink($path);

        return $manifest;
    }

    private function code(Store $store): string
    {
        return $this->actingAs($this->user)->withSession(['active_tenant_id' => $this->tenant->id])->postJson("/api/v1/stores/{$store->id}/pairing-codes")->assertCreated()->json('pairing_code');
    }

    private function register(string $secret): TestResponse
    {
        $query = http_build_query(['shop-id' => self::SHOP_ID, 'shop-url' => self::SHOP_URL, 'timestamp' => (string) time()]);

        return $this->call('GET', '/api/v1/shopware/app/register?'.$query, [], [], [], ['HTTP_SHOPWARE_APP_SIGNATURE' => hash_hmac('sha256', $query, $secret), 'HTTP_ACCEPT' => 'application/json']);
    }

    private function confirm(string $shopSecret): TestResponse
    {
        $body = json_encode(['apiKey' => self::API_KEY, 'secretKey' => self::API_SECRET, 'timestamp' => (string) time(), 'shopUrl' => self::SHOP_URL, 'shopId' => self::SHOP_ID], JSON_THROW_ON_ERROR);

        return $this->signed('confirm', $body, $shopSecret);
    }

    private function webhook(string $shopSecret, string $event, string $version = '1.4.0'): TestResponse
    {
        return $this->signed('webhook', json_encode(['data' => ['payload' => [], 'event' => $event], 'source' => ['url' => self::SHOP_URL, 'shopId' => self::SHOP_ID, 'appVersion' => $version], 'timestamp' => time()], JSON_THROW_ON_ERROR), $shopSecret);
    }

    private function signed(string $action, string $body, string $secret): TestResponse
    {
        return $this->call('POST', '/api/v1/shopware/app/'.$action, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_SHOPWARE_SHOP_SIGNATURE' => hash_hmac('sha256', $body, $secret)], $body);
    }

    private function moduleQuery(string $shopSecret): string
    {
        $query = http_build_query(['shop-id' => self::SHOP_ID, 'shop-url' => self::SHOP_URL, 'timestamp' => (string) time(), 'sw-version' => '6.7.2.2', 'sw-context-language' => '2fbb5fe2e29a4d70aa5854ce7ce3e20b', 'sw-user-language' => 'de-DE']);

        return $query.'&shopware-shop-signature='.hash_hmac('sha256', $query, $shopSecret);
    }

    private function module(string $shopSecret): TestResponse
    {
        return $this->get('/api/v1/shopware/app/connect?'.$this->moduleQuery($shopSecret));
    }

    private function connect(string $shopSecret, string $code): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->post('/api/v1/shopware/app/connect', ['signed_query' => $this->moduleQuery($shopSecret), 'code' => $code]);
    }
}
