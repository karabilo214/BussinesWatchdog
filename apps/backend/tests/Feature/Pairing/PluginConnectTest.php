<?php

namespace Tests\Feature\Pairing;

use App\Models\AuditLog;
use App\Models\Integration;
use App\Models\Membership;
use App\Models\PairingCode;
use App\Models\PlatformStaff;
use App\Models\PluginFunnelEvent;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Account\MfaService;
use App\Support\Account\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PluginConnectTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'https://shop.example.test';

    private const RETURN = 'https://shop.example.test/wp-admin/admin-post.php?action=business_watchdog_connect_return';

    private const VERIFIER = 'dGhpcy1pcy1hLXRlc3QtdmVyaWZpZXItb2YtNDMtY2hhcnM';

    private string $secret = '';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-28 10:00:00');
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_new_merchant_signs_up_from_the_plugin_connects_in_one_click_and_the_funnel_counts_each_stage_once(): void
    {
        $state = str_repeat('a1', 32);
        $this->get($this->connectUrl($state, ['start' => 'signup', 'lang' => 'de', 'placement' => 'plugins_card']))
            ->assertRedirect('/de/signup?utm_source=wordpress_plugin&utm_medium=connect');

        $this->get('/de/signup?utm_source=wordpress_plugin&utm_medium=connect')->assertOk()
            ->assertSee('data-plugin-connect', false)
            ->assertSee('shop.example.test')
            ->assertSee('href="/app/connect"', false);
        $this->get('/de/signup')->assertOk();

        $this->post('/de/signup', [
            'name' => 'Anna', 'organization_name' => 'Kaffee GmbH', 'email' => 'anna@shop.test', 'password' => 'correct-horse-battery', 'password_confirmation' => 'correct-horse-battery', 'timezone' => 'Europe/Berlin',
        ])->assertRedirect('/app/connect');

        $tenant = Tenant::query()->sole();
        $this->getJson('/api/v1/plugin-connect')->assertOk()
            ->assertJsonPath('site_host', 'shop.example.test')
            ->assertJsonPath('organization', 'Kaffee GmbH')
            ->assertJsonPath('stores', []);

        $store = $this->postJson('/api/v1/stores', [
            'name' => 'Kaffee', 'base_url' => self::SITE, 'timezone' => 'Europe/Berlin', 'default_currency' => 'EUR', 'platform' => 'woocommerce',
        ])->assertCreated()->json('id');
        $this->getJson('/api/v1/plugin-connect')->assertOk()->assertJsonPath('stores.0.id', $store)->assertJsonPath('stores.0.order_source', null);

        $redirect = (string) $this->postJson('/api/v1/plugin-connect', ['store_id' => $store])->assertOk()->json('redirect_url');
        $this->assertStringStartsWith(self::RETURN.'&state='.$state.'&code=bwpc_', $redirect);
        parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);
        $this->getJson('/api/v1/plugin-connect')->assertNotFound()->assertJsonPath('code', 'plugin_connect_missing');

        $audit = AuditLog::query()->where('action', AuditLog::ACTION_INTEGRATION_CONNECT_APPROVED)->sole();
        $this->assertSame(['provider' => 'woocommerce', 'site_host' => 'shop.example.test'], $audit->changes);
        $this->assertStringNotContainsString((string) $query['code'], json_encode($audit->changes));

        $this->exchange($query['code'], null)->assertStatus(422)->assertJsonPath('code', 'pairing_verifier_mismatch');
        $this->exchange($query['code'], str_repeat('x', 43))->assertStatus(422)->assertJsonPath('code', 'pairing_verifier_mismatch');
        $this->exchange($query['code'], self::VERIFIER)->assertCreated();
        $this->exchange($query['code'], self::VERIFIER)->assertStatus(409)->assertJsonPath('code', 'pairing_consumed');

        $this->assertSame(1, Integration::query()->where('store_id', $store)->where('provider', 'woocommerce')->where('status', 'active')->count());

        $events = PluginFunnelEvent::query()->orderBy('created_at')->get();
        $this->assertEqualsCanonicalizing(PluginFunnelEvent::STAGES, $events->pluck('stage')->all());
        $this->assertCount(1, $events->pluck('journey')->unique());
        $this->assertSame(['plugins_card'], $events->pluck('placement')->unique()->values()->all());
        $this->assertSame([$tenant->id], $events->pluck('tenant_id')->filter()->unique()->values()->all());

        $this->signInStaff();
        $report = $this->getJson('/platform-api/v1/plugin-funnel')->assertOk()->json();
        $this->assertSame([1, 1, 1, 1, 1], array_column($report['stages'], 'attributed'));
        $this->assertSame([null, 100, 100, 100, 100], array_map(fn ($value) => $value === null ? null : (int) $value, array_column($report['stages'], 'conversion_percent')));
        $this->assertSame('plugins_card', $report['placements'][0]['placement']);
    }

    public function test_a_signed_in_merchant_goes_straight_to_the_account_and_wrong_stores_are_refused(): void
    {
        [$user, $tenant, $store] = $this->userWithStore();
        $this->actingAs($user)->withSession(['active_tenant_id' => $tenant->id]);

        $this->get($this->connectUrl(str_repeat('b2', 32)))->assertRedirect('/app/connect');

        $other = Store::query()->create(['tenant_id' => $tenant->id, 'name' => 'Other', 'base_url' => 'https://other.example.test', 'timezone' => 'UTC', 'default_currency' => 'EUR', 'platform' => 'woocommerce']);
        $this->postJson('/api/v1/plugin-connect', ['store_id' => $other->id])->assertStatus(422)->assertJsonPath('code', 'plugin_connect_site_mismatch');

        $store->forceFill(['platform' => 'shopware'])->save();
        $this->postJson('/api/v1/plugin-connect', ['store_id' => $store->id])->assertStatus(422)->assertJsonPath('code', 'plugin_connect_wrong_platform');

        $store->forceFill(['platform' => 'woocommerce'])->save();
        Integration::query()->create(['tenant_id' => $tenant->id, 'store_id' => $store->id, 'provider' => 'woocommerce_api', 'mode' => 'live', 'source_authority' => Integration::SOURCE_STORE_REPORTED, 'status' => Integration::STATUS_ACTIVE, 'capabilities' => [], 'health' => [], 'connector_version' => '1']);
        $this->getJson('/api/v1/plugin-connect')->assertOk()->assertJsonPath('stores.0.order_source', 'woocommerce_api');
        $this->postJson('/api/v1/plugin-connect', ['store_id' => $store->id])->assertStatus(409)->assertJsonPath('code', 'order_source_already_connected');

        $foreign = Tenant::query()->create(['name' => 'Foreign', 'timezone' => 'UTC']);
        $foreignStore = Store::query()->create(['tenant_id' => $foreign->id, 'name' => 'Foreign', 'base_url' => self::SITE, 'timezone' => 'UTC', 'default_currency' => 'EUR', 'platform' => 'woocommerce']);
        $this->postJson('/api/v1/plugin-connect', ['store_id' => $foreignStore->id])->assertNotFound();
        $this->assertSame(0, PairingCode::query()->count());

        $this->postJson('/api/v1/plugin-connect/cancel')->assertNoContent();
        $this->getJson('/api/v1/plugin-connect')->assertNotFound();
    }

    public function test_a_viewer_cannot_approve_the_connection(): void
    {
        [$user, $tenant] = $this->userWithStore('viewer');
        $this->actingAs($user)->withSession(['active_tenant_id' => $tenant->id]);

        $this->get($this->connectUrl(str_repeat('c3', 32)))->assertRedirect('/app/connect');
        $this->getJson('/api/v1/plugin-connect')->assertForbidden();
    }

    public function test_the_connection_request_expires_after_thirty_minutes(): void
    {
        [$user, $tenant] = $this->userWithStore();
        $this->actingAs($user)->withSession(['active_tenant_id' => $tenant->id]);

        $this->get($this->connectUrl(str_repeat('c4', 32)))->assertRedirect('/app/connect');
        $this->getJson('/api/v1/plugin-connect')->assertOk();
        Carbon::setTestNow(Carbon::now()->addMinutes(31));
        $this->getJson('/api/v1/plugin-connect')->assertNotFound();
    }

    public function test_invalid_links_are_refused_without_keeping_anything(): void
    {
        $state = str_repeat('d4', 32);
        $cases = [
            ['site' => 'http://shop.example.test'],
            ['site' => 'https://localhost'],
            ['return' => 'https://evil.example.test/wp-admin/admin-post.php?action=business_watchdog_connect_return'],
            ['return' => 'https://shop.example.test/wp-admin/admin-post.php?action=business_watchdog_connect_return&next=https://evil.example.test'],
            ['return' => 'https://shop.example.test/wp-login.php?action=business_watchdog_connect_return'],
            ['return' => 'https://shop.example.test:8443/wp-admin/admin-post.php?action=business_watchdog_connect_return'],
            ['state' => 'short'],
            ['challenge' => 'not a challenge'],
        ];

        foreach ($cases as $case) {
            $this->get($this->connectUrl($state, $case))->assertStatus(422)->assertSessionMissing('plugin_connect');
        }

        $this->assertSame(0, PluginFunnelEvent::query()->count());
    }

    public function test_plugin_links_count_a_click_and_a_form_view_per_journey_and_manual_codes_reach_the_funnel(): void
    {
        $this->get('/go/wordpress-plugin?placement=wordpress_org&lang=ru&utm_campaign=ignored')
            ->assertRedirect('/ru/signup?utm_source=wordpress_plugin&utm_medium=onboarding&utm_content=wordpress_org');
        $this->get('/ru/signup')->assertOk()->assertDontSee('data-plugin-connect', false);
        $this->get('/en/signup')->assertOk();
        $this->get('/go/wordpress-plugin?placement=anything')->assertRedirect('/en/signup?utm_source=wordpress_plugin&utm_medium=onboarding&utm_content=plugin_description');

        $this->assertSame(['click' => 2, 'signup_view' => 1], PluginFunnelEvent::query()->get()->countBy('stage')->all());
        $this->assertSame(['wordpress_org', 'plugin_description'], PluginFunnelEvent::query()->where('stage', 'click')->orderBy('created_at')->pluck('placement')->all());

        [$user, $tenant, $store] = $this->userWithStore();
        $this->actingAs($user)->withSession(['active_tenant_id' => $tenant->id]);
        $this->postJson("/api/v1/stores/{$store->id}/pairing-codes")->assertCreated();
        $this->postJson("/api/v1/stores/{$store->id}/pairing-codes")->assertCreated();
        $this->assertSame(1, PluginFunnelEvent::query()->where('stage', 'code')->whereNull('journey')->count());
    }

    public function test_funnel_rows_older_than_thirteen_months_are_pruned(): void
    {
        $this->get('/go/wordpress-plugin');
        Carbon::setTestNow(Carbon::now()->addMonths(13)->addDay());
        $this->get('/go/wordpress-plugin');

        $this->artisan('plugin-funnel:prune')->expectsOutput('deleted=1')->assertSuccessful();
        $this->assertSame(1, PluginFunnelEvent::query()->count());
    }

    /**
     * @param  array<string, string>  $overrides
     */
    private function connectUrl(string $state, array $overrides = []): string
    {
        return '/connect/woocommerce?'.http_build_query($overrides + [
            'site' => self::SITE,
            'return' => self::RETURN,
            'state' => $state,
            'challenge' => rtrim(strtr(base64_encode(hash('sha256', self::VERIFIER, true)), '+/', '-_'), '='),
            'lang' => 'en',
            'placement' => 'plugin_page',
        ]);
    }

    private function exchange(string $code, ?string $verifier): TestResponse
    {
        return $this->postJson('/api/v1/pairing/exchange', array_filter([
            'pairing_code' => $code,
            'code_verifier' => $verifier,
            'install_id' => '550e8400-e29b-41d4-a716-446655440000',
            'connector_code' => 'woocommerce',
            'plugin_version' => '0.4.3',
            'base_url' => self::SITE,
        ], fn ($value) => $value !== null));
    }

    /**
     * @return array{0: User, 1: Tenant, 2: Store}
     */
    private function userWithStore(string $role = 'owner', string $baseUrl = self::SITE): array
    {
        $user = User::query()->create(['name' => 'Owner', 'email' => fake()->unique()->safeEmail(), 'password_hash' => Hash::make('very-secure-password'), 'locale' => 'en']);
        $tenant = Tenant::query()->create(['name' => 'Demo Store', 'timezone' => 'Europe/Berlin']);
        Membership::query()->create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => $role]);
        $store = Store::query()->create(['tenant_id' => $tenant->id, 'name' => 'Main Shop', 'base_url' => $baseUrl, 'timezone' => 'Europe/Berlin', 'default_currency' => 'EUR', 'platform' => 'woocommerce']);

        return [$user, $tenant, $store];
    }

    private function signInStaff(): void
    {
        $user = User::query()->create(['name' => 'Staff', 'email' => 'staff@platform.test', 'password_hash' => Hash::make('staff-password-1234'), 'locale' => 'ru']);
        $mfa = app(MfaService::class);
        $this->secret = $mfa->beginSetup($user)['secret'];
        $mfa->confirm($user, $this->code());
        PlatformStaff::query()->create(['user_id' => $user->id, 'role' => PlatformStaff::ROLE_OWNER, 'state' => PlatformStaff::STATE_ACTIVE]);
        $this->postJson('/platform-api/v1/auth/login', ['email' => 'staff@platform.test', 'password' => 'staff-password-1234'])->assertOk();
        $this->postJson('/platform-api/v1/auth/mfa', ['code' => $this->code()])->assertOk();
    }

    private function code(): string
    {
        Carbon::setTestNow(Carbon::now()->addSeconds(30));
        $totp = new Totp;

        return $totp->codeAt($this->secret, $totp->step(Carbon::now()->getTimestamp()));
    }
}
