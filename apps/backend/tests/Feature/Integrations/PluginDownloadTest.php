<?php

namespace Tests\Feature\Integrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Feature\Notifications\NotificationTestContext;
use Tests\TestCase;

class PluginDownloadTest extends TestCase
{
    use NotificationTestContext;
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/bw-plugins-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->directory);
        config()->set('watchdog.plugin_dir', $this->directory);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_members_download_the_newest_plugin_package_and_see_its_version(): void
    {
        $context = $this->context('viewer');
        $member = fn () => $this->actingAs($context['user'])->withSession(['active_tenant_id' => $context['tenant']->id]);

        $member()->getJson('/api/v1/downloads/woocommerce-plugin')->assertOk()->assertExactJson(['available' => false]);
        $member()->get('/api/v1/downloads/woocommerce-plugin/file')->assertNotFound()->assertJsonPath('code', 'plugin_not_available');

        file_put_contents($this->directory.'/business-watchdog-0.9.0.zip', 'old');
        file_put_contents($this->directory.'/business-watchdog-0.10.2.zip', 'newest-package');
        file_put_contents($this->directory.'/business-watchdog-latest.zip', 'ignored');
        file_put_contents($this->directory.'/other-1.0.0.zip', 'ignored');

        $member()->getJson('/api/v1/downloads/woocommerce-plugin')->assertOk()
            ->assertJsonPath('available', true)->assertJsonPath('version', '0.10.2')->assertJsonPath('file', 'business-watchdog-0.10.2.zip')
            ->assertJsonPath('size_bytes', 14)->assertJsonPath('sha256', hash('sha256', 'newest-package'))->assertJsonMissingPath('path');
        $download = $member()->get('/api/v1/downloads/woocommerce-plugin/file')->assertOk()->assertHeader('Content-Type', 'application/zip');
        $this->assertStringContainsString('business-watchdog-0.10.2.zip', (string) $download->headers->get('Content-Disposition'));
        $this->assertSame('newest-package', $download->streamedContent());
    }

    public function test_guests_cannot_download_the_plugin(): void
    {
        file_put_contents($this->directory.'/business-watchdog-1.0.0.zip', 'zip');
        $this->getJson('/api/v1/downloads/woocommerce-plugin')->assertUnauthorized();
        $this->getJson('/api/v1/downloads/woocommerce-plugin/file')->assertUnauthorized();
    }
}
