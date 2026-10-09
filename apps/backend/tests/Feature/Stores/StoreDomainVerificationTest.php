<?php

namespace Tests\Feature\Stores;

use App\Models\AuditLog;
use App\Models\Integration;
use App\Models\IntegrationCredential;
use App\Models\Membership;
use App\Models\Store;
use App\Models\StoreVerification;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Network\DnsClient;
use App\Support\Stores\StoreVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class StoreDomainVerificationTest extends TestCase
{
    use RefreshDatabase;

    private FakeDnsClient $dns;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dns = new FakeDnsClient;
        $this->app->instance(DnsClient::class, $this->dns);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_dns_txt_record_verifies_and_activates_the_store(): void
    {
        [$user, $tenant, $store] = $this->context();
        $older = $this->start($user, $tenant, $store, 'plugin_challenge')->json('id');
        $started = $this->start($user, $tenant, $store, 'dns')->assertStatus(202);
        $this->dns->txt['_bw-verify.shop.example.test'] = ['"'.$started->json('instructions.txt_value').'"'];

        $this->check($user, $tenant, $store)
            ->assertOk()
            ->assertJsonPath('state', 'verified')
            ->assertJsonPath('reason_code', null);

        $store->refresh();
        $this->assertNotNull($store->verified_at);
        $this->assertSame('active', $store->status);
        $this->assertSame(StoreVerification::STATUS_EXPIRED, StoreVerification::query()->findOrFail($older)->status);
        $this->assertDatabaseHas('audit_log', ['entity_id' => $store->id, 'action' => AuditLog::ACTION_STORE_VERIFIED]);
    }

    public function test_missing_dns_record_keeps_the_verification_pending_with_a_reason(): void
    {
        [$user, $tenant, $store] = $this->context();
        $this->start($user, $tenant, $store, 'dns');
        $this->dns->txt['_bw-verify.shop.example.test'] = ['bw-something-else'];

        $this->check($user, $tenant, $store)
            ->assertOk()
            ->assertJsonPath('state', 'pending')
            ->assertJsonPath('reason_code', StoreVerificationService::ERROR_DNS_RECORD_MISSING)
            ->assertJsonPath('attempts', 1);

        $this->assertNull($store->fresh()->verified_at);
    }

    public function test_connector_challenge_served_by_the_store_verifies_it(): void
    {
        [$user, $tenant, $store] = $this->context();
        $started = $this->start($user, $tenant, $store, 'plugin_challenge');
        $this->dns->ips['shop.example.test'] = ['93.184.216.34'];
        Http::fake([$started->json('instructions.url') => Http::response($started->json('instructions.body')."\n", 200)]);

        $this->check($user, $tenant, $store)->assertOk()->assertJsonPath('state', 'verified');
    }

    public function test_challenge_fetch_refuses_private_destinations_without_sending_a_request(): void
    {
        [$user, $tenant, $store] = $this->context();
        $this->start($user, $tenant, $store, 'plugin_challenge');
        $this->dns->ips['shop.example.test'] = ['93.184.216.34', '10.0.0.7'];
        Http::fake();

        $this->check($user, $tenant, $store)
            ->assertOk()
            ->assertJsonPath('state', 'pending')
            ->assertJsonPath('reason_code', 'unsafe_destination');

        Http::assertNothingSent();
    }

    public function test_redirects_and_wrong_bodies_do_not_verify(): void
    {
        [$user, $tenant, $store] = $this->context();
        $started = $this->start($user, $tenant, $store, 'plugin_challenge');
        $this->dns->ips['shop.example.test'] = ['93.184.216.34'];
        Http::fake([$started->json('instructions.url') => Http::sequence()
            ->push('', 302, ['Location' => 'https://169.254.169.254/latest'])
            ->push('bw-not-the-challenge', 200)]);

        $this->check($user, $tenant, $store)->assertJsonPath('reason_code', 'redirect_not_allowed');
        $this->check($user, $tenant, $store)->assertJsonPath('reason_code', StoreVerificationService::ERROR_CHALLENGE_MISMATCH);
        $this->assertNull($store->fresh()->verified_at);
    }

    public function test_changing_the_store_url_fails_the_pending_verification(): void
    {
        [$user, $tenant, $store] = $this->context();
        $started = $this->start($user, $tenant, $store, 'dns');
        $store->forceFill(['base_url' => 'https://new-shop.example.test'])->save();
        $this->dns->txt['_bw-verify.shop.example.test'] = [$started->json('instructions.txt_value')];

        $this->check($user, $tenant, $store)
            ->assertJsonPath('state', 'failed')
            ->assertJsonPath('reason_code', StoreVerificationService::ERROR_STORE_URL_CHANGED);
    }

    public function test_heartbeat_hands_the_pending_challenge_to_the_connector(): void
    {
        [$user, $tenant, $store] = $this->context();
        $started = $this->start($user, $tenant, $store, 'plugin_challenge');
        [$keyId, $secret] = $this->connector($store);

        $this->signedHeartbeat($keyId, $secret)
            ->assertOk()
            ->assertJsonPath('store_verification.id', $started->json('id'))
            ->assertJsonPath('store_verification.challenge', $started->json('instructions.body'))
            ->assertJsonPath('store_verification.url', 'https://shop.example.test/wp-json/business-watchdog/v1/challenge/'.$started->json('id'));
    }

    public function test_background_command_checks_due_verifications_and_expires_stale_ones(): void
    {
        [$user, $tenant, $store] = $this->context();
        $started = $this->start($user, $tenant, $store, 'dns');
        $this->dns->txt['_bw-verify.shop.example.test'] = [];

        $this->artisan('stores:check-verifications')->assertSuccessful();
        $this->artisan('stores:check-verifications')->assertSuccessful();
        $this->assertSame(1, StoreVerification::query()->findOrFail($started->json('id'))->attempts);

        $this->dns->txt['_bw-verify.shop.example.test'] = [$started->json('instructions.txt_value')];
        Carbon::setTestNow(now()->addSeconds(61));
        $this->artisan('stores:check-verifications')->assertSuccessful();
        $this->assertSame(StoreVerification::STATUS_VERIFIED, StoreVerification::query()->findOrFail($started->json('id'))->status);

        $expiring = $this->start($user, $tenant, $store, 'plugin_challenge')->json('id');
        Carbon::setTestNow(now()->addHour());
        $this->artisan('stores:check-verifications')->assertSuccessful();
        $this->assertSame(StoreVerification::STATUS_EXPIRED, StoreVerification::query()->findOrFail($expiring)->status);
    }

    public function test_browser_checks_and_activation_require_a_verified_domain(): void
    {
        [$user, $tenant, $store] = $this->context();

        foreach ([['browser_enabled' => true], ['status' => 'active']] as $payload) {
            $this->actingAs($user)
                ->withSession(['active_tenant_id' => $tenant->id])
                ->withHeader('If-Match', (string) $store->fresh()->config_version)
                ->patchJson("/api/v1/stores/{$store->id}", $payload)
                ->assertStatus(422)
                ->assertJsonPath('code', 'store_not_verified');
        }
    }

    public function test_store_urls_pointing_to_private_or_non_standard_destinations_are_rejected(): void
    {
        [$user, $tenant] = $this->context();

        foreach (['https://localhost', 'https://10.0.0.5', 'https://169.254.169.254', 'https://shop.internal', 'https://shop.example.test:8443', 'https://[::1]'] as $url) {
            $this->actingAs($user)
                ->withSession(['active_tenant_id' => $tenant->id])
                ->postJson('/api/v1/stores', [
                    'name' => 'Shop',
                    'base_url' => $url,
                    'timezone' => 'Europe/Kyiv',
                    'default_currency' => 'EUR',
                ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('base_url');
        }
    }

    /**
     * @return array{User, Tenant, Store}
     */
    private function context(): array
    {
        $user = User::query()->create([
            'name' => 'Owner',
            'email' => fake()->unique()->safeEmail(),
            'password_hash' => Hash::make('very-secure-password'),
            'locale' => 'ru',
        ]);
        $tenant = Tenant::query()->create(['name' => 'Demo', 'timezone' => 'Europe/Kyiv']);
        Membership::query()->create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => 'owner']);
        $store = Store::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Main Shop',
            'base_url' => 'https://shop.example.test',
            'timezone' => 'Europe/Kyiv',
            'default_currency' => 'EUR',
        ]);

        return [$user, $tenant, $store];
    }

    private function start(User $user, Tenant $tenant, Store $store, string $method): TestResponse
    {
        return $this->actingAs($user)
            ->withSession(['active_tenant_id' => $tenant->id])
            ->postJson("/api/v1/stores/{$store->id}/verify", ['method' => $method]);
    }

    private function check(User $user, Tenant $tenant, Store $store): TestResponse
    {
        return $this->actingAs($user)
            ->withSession(['active_tenant_id' => $tenant->id])
            ->postJson("/api/v1/stores/{$store->id}/verification/check");
    }

    /**
     * @return array{string, string}
     */
    private function connector(Store $store): array
    {
        $integration = Integration::query()->create([
            'tenant_id' => $store->tenant_id,
            'store_id' => $store->id,
            'provider' => 'woocommerce',
            'install_id' => (string) Str::uuid(),
            'mode' => 'live',
            'source_authority' => Integration::SOURCE_STORE_REPORTED,
            'status' => Integration::STATUS_ACTIVE,
            'capabilities' => [],
            'connector_version' => '1.0.0',
            'health' => [],
        ]);
        $secret = random_bytes(32);
        IntegrationCredential::query()->create([
            'tenant_id' => $store->tenant_id,
            'store_id' => $store->id,
            'integration_id' => $integration->id,
            'kind' => IntegrationCredential::KIND_PLUGIN_HMAC,
            'key_id' => 'bwk_verification_test',
            'ciphertext' => Crypt::encryptString(base64_encode($secret)),
            'key_version' => 1,
            'fingerprint' => hash('sha256', $secret),
            'status' => IntegrationCredential::STATUS_ACTIVE,
            'created_at' => now(),
        ]);

        return ['bwk_verification_test', $secret];
    }

    private function signedHeartbeat(string $keyId, string $secret): TestResponse
    {
        $timestamp = (string) now()->getTimestamp();
        $nonce = (string) Str::uuid();
        $body = '{}';

        return $this->call('POST', '/api/v1/ingest/heartbeat', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_BW_KEY_ID' => $keyId,
            'HTTP_X_BW_TIMESTAMP' => $timestamp,
            'HTTP_X_BW_NONCE' => $nonce,
            'HTTP_X_BW_SIGNATURE' => hash_hmac('sha256', implode("\n", ['v1', $timestamp, $nonce, 'POST', '/api/v1/ingest/heartbeat', hash('sha256', $body)]), $secret),
            'HTTP_X_BW_SIGNATURE_VERSION' => '1',
        ], $body);
    }
}

class FakeDnsClient implements DnsClient
{
    /**
     * @var array<string, list<string>>
     */
    public array $ips = [];

    /**
     * @var array<string, list<string>>
     */
    public array $txt = [];

    public function resolveIps(string $host): array
    {
        return $this->ips[$host] ?? [];
    }

    public function txtRecords(string $name): array
    {
        return $this->txt[$name] ?? [];
    }
}
