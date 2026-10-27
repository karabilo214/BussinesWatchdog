<?php

namespace Tests\Feature\Providers;

use App\Support\Network\DnsClient;
use App\Support\Providers\Shopware\ShopwareApp;
use App\Support\Providers\Shopware\ShopwareStoreApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Stores\FakeDnsClient;
use Tests\TestCase;
use ZipArchive;

class ShopwareStoreAppTest extends TestCase
{
    use RefreshDatabase;

    private const SHOP_URL = 'https://shop.example.test';

    private const SHOP_ID = 'aBcDeFgHiJkLmNoP';

    private const STORE_SECRET = 'secret-issued-by-the-shopware-account';

    protected function setUp(): void
    {
        parent::setUp();

        $dns = new FakeDnsClient;
        $dns->ips['shop.example.test'] = ['93.184.216.34'];
        $this->app->instance(DnsClient::class, $dns);
    }

    public function test_the_store_package_has_the_partner_name_no_secret_an_icon_and_the_universal_app_files(): void
    {
        $directory = sys_get_temp_dir().'/bw-store-app-'.bin2hex(random_bytes(4));
        mkdir($directory);

        $this->artisan('shopware:app-store-package', ['directory' => $directory])->assertFailed();

        config(['watchdog.shopware.store_app_name' => 'BwdogBusinessWatchdog']);
        $fingerprint = app(ShopwareApp::class)->releaseFingerprint();
        $this->artisan('shopware:app-store-package', ['directory' => $directory])->assertSuccessful();
        $path = $directory.'/BwdogBusinessWatchdog-'.ShopwareApp::APP_VERSION.'.zip';

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $manifest = (string) $zip->getFromName('BwdogBusinessWatchdog/manifest.xml');
        $this->assertStringContainsString('<name>BwdogBusinessWatchdog</name>', $manifest);
        $this->assertStringNotContainsString('<secret>', $manifest);
        $this->assertStringContainsString('<registrationUrl>'.url('/api/v1/shopware/app/register').'</registrationUrl>', $manifest);
        $this->assertStringContainsString('<main-module source="'.url('/api/v1/shopware/app/connect').'"/>', $manifest);
        $this->assertStringContainsString('<icon>Resources/config/plugin.png</icon>', $manifest);
        $this->assertStringContainsString('<compatibility>~6.6.0 || ~6.7.0</compatibility>', $manifest);
        $this->assertStringContainsString('<privacy>'.rtrim((string) config('app.url'), '/').'/en/privacy</privacy>', $manifest);
        $this->assertStringContainsString(ShopwareApp::permissionsXml(), $manifest);
        $this->assertNotFalse($zip->getFromName('BwdogBusinessWatchdog/Resources/config/plugin.png'));

        foreach (app(ShopwareApp::class)->sourceFiles() as $relative => $contents) {
            $this->assertSame($contents, $zip->getFromName('BwdogBusinessWatchdog/'.$relative));
        }

        $zip->close();
        @unlink($path);
        @rmdir($directory);
        $this->assertSame($fingerprint, app(ShopwareApp::class)->releaseFingerprint(), 'building the Store package does not touch the approved release');

        config(['watchdog.shopware.store_app_name' => ShopwareApp::APP_NAME]);
        $this->assertNull(ShopwareStoreApp::name(), 'the Store app needs the partner prefix');
    }

    public function test_the_store_app_registers_with_the_secret_from_the_shopware_account(): void
    {
        $this->register(self::STORE_SECRET)->assertStatus(401);

        config(['watchdog.shopware.store_app_name' => 'BwdogBusinessWatchdog', 'watchdog.shopware.store_app_secret' => self::STORE_SECRET]);
        $store = $this->register(self::STORE_SECRET)->assertOk();
        $this->assertSame(hash_hmac('sha256', self::SHOP_ID.self::SHOP_URL.'BwdogBusinessWatchdog', self::STORE_SECRET), $store->json('proof'));
        $this->assertSame(url('/api/v1/shopware/app/confirm'), $store->json('confirmation_url'));

        $universal = $this->register(ShopwareApp::appSecret())->assertOk();
        $this->assertSame(hash_hmac('sha256', self::SHOP_ID.self::SHOP_URL.ShopwareApp::APP_NAME, ShopwareApp::appSecret()), $universal->json('proof'));

        $this->register('some-other-secret')->assertStatus(401);
    }

    private function register(string $secret): TestResponse
    {
        $query = http_build_query(['shop-id' => self::SHOP_ID, 'shop-url' => self::SHOP_URL, 'timestamp' => (string) time()]);

        return $this->call('GET', '/api/v1/shopware/app/register?'.$query, [], [], [], ['HTTP_SHOPWARE_APP_SIGNATURE' => hash_hmac('sha256', $query, $secret), 'HTTP_ACCEPT' => 'application/json']);
    }
}
