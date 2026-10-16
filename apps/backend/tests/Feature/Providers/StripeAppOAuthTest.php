<?php

namespace Tests\Feature\Providers;

use App\Models\Integration;
use App\Models\Membership;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Providers\Stripe\StripeSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StripeAppOAuthTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Store $store;

    /** @var list<array{url: string, method: string}> */
    private array $calls = [];

    /** @var array<string, mixed> */
    private array $tokenOverrides = [];

    private bool $rejectRefresh = false;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'watchdog.stripe.api_base' => 'https://stripe.test',
            'watchdog.stripe.app.client_id_test' => 'ca_test_app',
            'watchdog.stripe.app.install_url_test' => 'https://marketplace.stripe.com/oauth/v2/authorize',
            'watchdog.stripe.app.secret_key_test' => 'sk_test_platform_key',
            'watchdog.stripe.app.redirect_uri' => 'https://watchdog.example/api/v1/stripe-app/callback',
        ]);
        $this->owner = User::query()->create(['name' => 'Owner', 'email' => 'oauth@example.test', 'password_hash' => Hash::make('secure-password'), 'locale' => 'ru']);
        $tenant = Tenant::query()->create(['name' => 'OAuth tenant', 'timezone' => 'Europe/Berlin']);
        Membership::query()->create(['tenant_id' => $tenant->id, 'user_id' => $this->owner->id, 'role' => 'owner']);
        $this->store = Store::query()->create(['tenant_id' => $tenant->id, 'name' => 'Shop', 'base_url' => 'https://shop.example.test', 'timezone' => 'Europe/Berlin', 'default_currency' => 'EUR']);

        Http::fake(function (HttpRequest $request) {
            $this->calls[] = ['url' => $request->url(), 'method' => $request->method()];
            if (str_ends_with($request->url(), '/v1/oauth/token')) {
                if ($this->rejectRefresh && count(array_filter($this->calls, fn ($call) => $call['method'] === 'POST')) > 1) {
                    return Http::response(['error' => 'invalid_grant'], 400);
                }

                return Http::response(array_merge([
                    'access_token' => count(array_filter($this->calls, fn ($call) => $call['method'] === 'POST')) > 1 ? 'app_access_2' : 'app_access_1',
                    'refresh_token' => count(array_filter($this->calls, fn ($call) => $call['method'] === 'POST')) > 1 ? 'app_refresh_2' : 'app_refresh_1',
                    'scope' => 'stripe_apps', 'livemode' => false, 'stripe_user_id' => 'acct_AppTest',
                ], $this->tokenOverrides));
            }

            return Http::response(['data' => [], 'has_more' => false]);
        });
    }

    public function test_install_state_is_one_use_and_tokens_are_encrypted_then_refreshed_for_polling(): void
    {
        $url = $this->actingAs($this->owner)->withSession(['active_tenant_id' => $this->store->tenant_id])
            ->postJson("/api/v1/stores/{$this->store->id}/integrations/stripe-app/start", ['mode' => 'test'])
            ->assertOk()->json('url');
        $this->assertStringStartsWith('https://marketplace.stripe.com/oauth/v2/authorize?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('ca_test_app', $query['client_id']);
        $this->assertArrayHasKey('state', $query);
        $this->assertFalse(DB::table('stripe_app_oauth_attempts')->where('state_hash', $query['state'])->exists());

        $this->get('/api/v1/stripe-app/callback?'.http_build_query(['state' => $query['state'], 'code' => 'ac_test']))
            ->assertRedirect(url('/app/stores/'.$this->store->id).'?stripe_app=connected');
        $integration = Integration::query()->where('store_id', $this->store->id)->where('provider', 'stripe')->firstOrFail();
        $this->assertSame('stripe_app_oauth', $integration->health['auth_method']);
        $this->assertSame('acct_AppTest', $integration->external_account_id);
        $connection = DB::table('stripe_app_oauth_connections')->where('integration_id', $integration->id)->first();
        $this->assertNotNull($connection);
        $this->assertStringNotContainsString('app_access_1', $connection->access_ciphertext);
        $this->assertStringNotContainsString('app_refresh_1', $connection->refresh_ciphertext);
        $this->assertSame(3, count(array_filter($this->calls, fn ($call) => $call['method'] === 'GET')));

        $this->get('/api/v1/stripe-app/callback?'.http_build_query(['state' => $query['state'], 'code' => 'ac_test']))
            ->assertStatus(400)->assertJsonPath('code', 'stripe_app_invalid_state');
        DB::table('stripe_app_oauth_connections')->where('integration_id', $integration->id)->update(['access_expires_at' => now()->subMinute()]);
        $result = app(StripeSync::class)->run($integration);
        $this->assertSame('ok', $result['status']);
        $this->assertSame(2, count(array_filter($this->calls, fn ($call) => $call['method'] === 'POST')));
    }

    public function test_install_is_unavailable_without_app_configuration_and_existing_provider_is_not_replaced(): void
    {
        config(['watchdog.stripe.app.client_id_test' => null]);
        $this->actingAs($this->owner)->withSession(['active_tenant_id' => $this->store->tenant_id])
            ->getJson("/api/v1/stores/{$this->store->id}/integrations/stripe-app/availability")
            ->assertOk()->assertJsonPath('test', false)->assertJsonPath('live', false);
        $this->actingAs($this->owner)->withSession(['active_tenant_id' => $this->store->tenant_id])
            ->postJson("/api/v1/stores/{$this->store->id}/integrations/stripe-app/start", ['mode' => 'test'])
            ->assertStatus(409)->assertJsonPath('code', 'stripe_app_unconfigured');
        $this->assertSame(0, DB::table('stripe_app_oauth_attempts')->count());
    }

    public function test_wrong_scope_is_rejected_without_creating_an_integration(): void
    {
        $this->tokenOverrides['scope'] = 'read_write';
        $url = $this->actingAs($this->owner)->withSession(['active_tenant_id' => $this->store->tenant_id])
            ->postJson("/api/v1/stores/{$this->store->id}/integrations/stripe-app/start", ['mode' => 'test'])
            ->assertOk()->json('url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->get('/api/v1/stripe-app/callback?'.http_build_query(['state' => $query['state'], 'code' => 'ac_wrong']))
            ->assertRedirect(url('/app/stores/'.$this->store->id).'?stripe_app=failed&stripe_error=stripe_app_invalid_grant');
        $this->assertSame(0, Integration::query()->where('provider', 'stripe')->count());
    }

    public function test_other_tenant_cannot_start_an_install(): void
    {
        $other = Tenant::query()->create(['name' => 'Other', 'timezone' => 'Europe/Berlin']);
        Membership::query()->create(['tenant_id' => $other->id, 'user_id' => $this->owner->id, 'role' => 'owner']);
        $this->actingAs($this->owner)->withSession(['active_tenant_id' => $other->id])
            ->postJson("/api/v1/stores/{$this->store->id}/integrations/stripe-app/start", ['mode' => 'test'])->assertNotFound();
    }

    public function test_rejected_refresh_degrades_provider_coverage(): void
    {
        $url = $this->actingAs($this->owner)->withSession(['active_tenant_id' => $this->store->tenant_id])
            ->postJson("/api/v1/stores/{$this->store->id}/integrations/stripe-app/start", ['mode' => 'test'])->assertOk()->json('url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->get('/api/v1/stripe-app/callback?'.http_build_query(['state' => $query['state'], 'code' => 'ac_test']))->assertRedirect();
        $integration = Integration::query()->where('store_id', $this->store->id)->where('provider', 'stripe')->firstOrFail();
        DB::table('stripe_app_oauth_connections')->where('integration_id', $integration->id)->update(['access_expires_at' => now()->subMinute()]);

        $this->rejectRefresh = true;
        $result = app(StripeSync::class)->run($integration);
        $this->assertSame('failed', $result['status']);
        $this->assertSame('stripe_key_rejected', $result['error']);
        $this->assertSame(Integration::STATUS_DEGRADED, $integration->fresh()->status);
    }
}
