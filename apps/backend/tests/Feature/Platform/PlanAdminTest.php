<?php

namespace Tests\Feature\Platform;

use App\Models\BillingPlan;
use App\Models\BillingPlanVersion;
use App\Models\BillingPrice;
use App\Models\PlatformStaff;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Account\MfaService;
use App\Support\Account\Totp;
use App\Support\Billing\FeatureCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Notifications\NotificationTestContext;
use Tests\TestCase;

class PlanAdminTest extends TestCase
{
    use NotificationTestContext;
    use RefreshDatabase;

    private const API = 'https://sandbox-api.paddle.com';

    /** @var array<string, string> */
    private array $secrets = [];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-12T10:00:00Z');
        config()->set('watchdog.billing.paddle.environment', 'sandbox');
        config()->set('watchdog.billing.paddle.api_key', 'pdl_sdbx_apikey_test');
        config()->set('watchdog.billing.paddle.client_token', 'test_client_token');
        config()->set('watchdog.billing.paddle.account_ref', 'default');
    }

    public function test_owner_builds_a_draft_with_any_billing_period_and_publishes_it_to_paddle_after_a_code(): void
    {
        $this->signIn('owner@example.test', PlatformStaff::ROLE_OWNER);
        Http::fake([
            self::API.'/products' => Http::response(['data' => ['id' => 'pro_team']]),
            self::API.'/prices' => Http::sequence()->push(['data' => ['id' => 'pri_team_base']])->push(['data' => ['id' => 'pri_team_extra']]),
        ]);

        $draft = $this->postJson('/platform-api/v1/plans', ['code' => 'team', 'name' => ['ru' => 'Команда', 'en' => 'Team']])
            ->assertCreated()->assertJsonPath('state', 'draft')->assertJsonPath('version', 1)->json();
        $this->postJson('/platform-api/v1/plans', ['code' => 'team', 'name' => ['en' => 'Again']])->assertStatus(409)->assertJsonPath('code', 'plan_code_taken');
        $this->postJson('/platform-api/v1/plans', ['code' => 'Team Plan!', 'name' => ['en' => 'X']])->assertStatus(422)->assertJsonPath('code', 'plan_code_invalid');

        $this->patchJson("/platform-api/v1/plan-versions/{$draft['id']}", [
            'name' => ['ru' => 'Команда', 'en' => 'Team', 'de' => 'Team'],
            'description' => ['ru' => 'Для агентств', 'en' => 'For agencies'],
            'trial_days' => 30,
            'entitlements' => ['features' => [FeatureCatalog::CHECKOUT_MONITORING, FeatureCatalog::MONEY_RECONCILIATION], 'limits' => [FeatureCatalog::MAX_ACTIVE_STORES => 2, FeatureCatalog::MIN_CHECK_INTERVAL_MINUTES => 10, FeatureCatalog::MAX_EXTRA_STORES => 8, FeatureCatalog::MONTHLY_EVENT_BUDGET => null]],
        ])->assertOk()->assertJsonPath('trial_days', 30)->assertJsonPath('entitlements.limits.max_active_stores', 2)->assertJsonPath('description.en', 'For agencies');
        $this->patchJson("/platform-api/v1/plan-versions/{$draft['id']}", ['entitlements' => ['features' => [], 'limits' => [FeatureCatalog::MIN_CHECK_INTERVAL_MINUTES => 1]]])
            ->assertStatus(422)->assertJsonPath('code', 'entitlement_limit_invalid');
        $this->patchJson("/platform-api/v1/plan-versions/{$draft['id']}", ['entitlements' => ['features' => ['teleport'], 'limits' => []]])
            ->assertStatus(422)->assertJsonPath('code', 'entitlement_feature_unknown');
        $this->patchJson("/platform-api/v1/plan-versions/{$draft['id']}", ['trial_days' => 400])->assertStatus(422)->assertJsonPath('code', 'trial_days_invalid');

        $this->publish($draft['id'], 'owner@example.test')->assertStatus(422)->assertJsonPath('code', 'base_price_missing');
        $this->price($draft['id'], ['kind' => 'base', 'currency' => 'eur', 'amount_minor' => '4500', 'interval' => 'week', 'interval_count' => 2])
            ->assertCreated()->assertJsonPath('prices.0.amount_minor', '4500')->assertJsonPath('prices.0.interval_count', 2);
        $this->price($draft['id'], ['kind' => 'base', 'currency' => 'EUR', 'amount_minor' => '4500', 'interval' => 'week', 'interval_count' => 2])->assertStatus(409)->assertJsonPath('code', 'price_exists');
        $this->price($draft['id'], ['kind' => 'base', 'currency' => 'XYZ', 'amount_minor' => '1', 'interval' => 'day', 'interval_count' => 1])->assertStatus(422)->assertJsonPath('code', 'price_currency_invalid');
        $this->price($draft['id'], ['kind' => 'base', 'currency' => 'EUR', 'amount_minor' => '12.50', 'interval' => 'day', 'interval_count' => 1])->assertStatus(422)->assertJsonPath('code', 'price_amount_invalid');
        $this->price($draft['id'], ['kind' => 'base', 'currency' => 'EUR', 'amount_minor' => '100', 'interval' => 'fortnight', 'interval_count' => 1])->assertStatus(422)->assertJsonPath('code', 'price_interval_invalid');
        $this->price($draft['id'], ['kind' => 'base', 'currency' => 'EUR', 'amount_minor' => '100', 'interval' => 'day', 'interval_count' => 400])->assertStatus(422)->assertJsonPath('code', 'price_interval_invalid');
        $daily = $this->price($draft['id'], ['kind' => 'base', 'currency' => 'EUR', 'amount_minor' => '100', 'interval' => 'day', 'interval_count' => 1])->assertCreated()->json('prices');
        $this->deleteJson('/platform-api/v1/plan-prices/'.collect($daily)->firstWhere('interval', 'day')['id'])->assertNoContent();
        $this->price($draft['id'], ['kind' => 'extra_store', 'currency' => 'EUR', 'amount_minor' => '1500', 'interval' => 'week', 'interval_count' => 2])->assertCreated();
        $this->assertSame(0, Http::recorded()->count());

        $this->postJson("/platform-api/v1/plan-versions/{$draft['id']}/publish", ['reason' => 'Launch', 'code' => '000000'])->assertForbidden()->assertJsonPath('code', 'step_up_failed');
        $this->publish($draft['id'], 'owner@example.test')
            ->assertOk()->assertJsonPath('state', 'published')->assertJsonPath('prices.0.state', 'published');

        Http::assertSent(fn (Request $request): bool => $request->url() === self::API.'/prices'
            && $request['billing_cycle'] === ['interval' => 'week', 'frequency' => 2]
            && $request['unit_price'] === ['amount' => '4500', 'currency_code' => 'EUR']);
        Http::assertSent(fn (Request $request): bool => $request->url() === self::API.'/prices' && $request['quantity'] === ['minimum' => 1, 'maximum' => 8]);
        $this->patchJson("/platform-api/v1/plan-versions/{$draft['id']}", ['trial_days' => 7])->assertStatus(409)->assertJsonPath('code', 'version_not_draft');

        $customer = $this->context();
        $catalog = $this->actingAs($customer['user'])->withSession(['active_tenant_id' => $customer['tenant']->id])->getJson('/api/v1/billing/plans')->assertOk();
        $this->assertSame('team', $catalog->json('data.0.plan_code'));
        $this->assertSame(['week', 2], [$catalog->json('data.0.prices.0.interval'), $catalog->json('data.0.prices.0.interval_count')]);
        $this->assertSame(30, $catalog->json('data.0.trial_days'));

        $this->assertSame(
            ['plan.created', 'plan_version.updated', 'plan_price.added', 'plan_price.added', 'plan_price.removed', 'plan_price.added', 'plan_version.published:denied', 'plan_version.published'],
            DB::table('platform_audit_log')->where('action', 'like', 'plan%')->orderBy('id')->get()->map(fn ($row) => $row->result === 'succeeded' ? $row->action : $row->action.':'.$row->result)->all(),
        );
    }

    public function test_a_new_version_replaces_the_old_one_for_new_buyers_without_touching_its_subscribers(): void
    {
        $this->signIn('owner@example.test', PlatformStaff::ROLE_OWNER);
        $v1 = $this->publishedPlan();
        $tenant = Tenant::query()->create(['name' => 'Shop GmbH', 'timezone' => 'Europe/Berlin']);
        Subscription::query()->create(['tenant_id' => $tenant->id, 'provider' => 'paddle', 'plan_code' => 'start', 'plan_version_id' => $v1->id, 'entitlements' => $v1->entitlements, 'status' => Subscription::STATUS_ACTIVE, 'period_end' => now()->addMonth()]);
        Http::fake([self::API.'/prices' => Http::response(['data' => ['id' => 'pri_start_v2']])]);

        $v2 = $this->postJson("/platform-api/v1/plans/{$v1->plan_id}/versions")->assertCreated()->assertJsonPath('version', 2)->assertJsonPath('prices.0.amount_minor', '1900')->json();
        $this->postJson("/platform-api/v1/plans/{$v1->plan_id}/versions")->assertStatus(409)->assertJsonPath('code', 'draft_exists');
        $this->deleteJson('/platform-api/v1/plan-prices/'.$v2['prices'][0]['id'])->assertNoContent();
        $this->price($v2['id'], ['kind' => 'base', 'currency' => 'EUR', 'amount_minor' => '2400', 'interval' => 'month', 'interval_count' => 1])->assertCreated();
        $this->publish($v2['id'], 'owner@example.test')->assertOk();

        $this->assertSame(BillingPlan::STATE_RETIRED, $v1->fresh()->state);
        $this->assertSame(BillingPlan::STATE_RETIRED, BillingPrice::query()->where('plan_version_id', $v1->id)->value('state'));
        $this->assertSame($v1->id, Subscription::query()->value('plan_version_id'));
        $versions = collect($this->getJson('/platform-api/v1/plans')->assertOk()->json('data.0.versions'))->keyBy('version');
        $this->assertSame([1, 0], [$versions[1]['subscribers'], $versions[2]['subscribers']]);

        $this->postJson("/platform-api/v1/plan-versions/{$v2['id']}/retire", ['reason' => 'Stop selling', 'code' => $this->code('owner@example.test')])->assertOk()->assertJsonPath('state', 'retired');
        $customer = $this->context();
        $this->actingAs($customer['user'])->withSession(['active_tenant_id' => $customer['tenant']->id])->getJson('/api/v1/billing/plans')->assertJsonPath('data', []);
    }

    public function test_a_paddle_failure_keeps_the_version_a_draft(): void
    {
        $this->signIn('owner@example.test', PlatformStaff::ROLE_OWNER);
        Http::fake([self::API.'/*' => Http::response(['error' => ['code' => 'internal_error']], 500)]);
        $draft = $this->postJson('/platform-api/v1/plans', ['code' => 'pro', 'name' => ['ru' => 'Про', 'en' => 'Pro', 'de' => 'Pro']])->json();
        $this->price($draft['id'], ['kind' => 'base', 'currency' => 'EUR', 'amount_minor' => '4900', 'interval' => 'month', 'interval_count' => 1]);

        $this->publish($draft['id'], 'owner@example.test')->assertStatus(502)->assertJsonPath('code', 'provider_unavailable');

        $this->assertSame(BillingPlan::STATE_DRAFT, BillingPlanVersion::query()->whereKey($draft['id'])->value('state'));
        $this->assertSame('failed', DB::table('platform_audit_log')->where('action', 'plan_version.published')->value('result'));
    }

    public function test_roles_limit_who_reads_and_changes_plans(): void
    {
        $this->signIn('admin@example.test', PlatformStaff::ROLE_ADMIN);
        $this->getJson('/platform-api/v1/plans')->assertOk()->assertJsonPath('catalog.intervals', ['day', 'week', 'month', 'year']);
        $this->postJson('/platform-api/v1/plans', ['code' => 'x1', 'name' => ['en' => 'X']])->assertForbidden()->assertJsonPath('code', 'platform_forbidden');

        $this->postJson('/platform-api/v1/auth/logout');
        $this->signIn('support@example.test', PlatformStaff::ROLE_SUPPORT);
        $this->getJson('/platform-api/v1/plans')->assertForbidden();
    }

    private function publishedPlan(): BillingPlanVersion
    {
        $plan = BillingPlan::query()->create(['code' => 'start', 'state' => BillingPlan::STATE_PUBLISHED]);
        $version = BillingPlanVersion::query()->create([
            'plan_id' => $plan->id,
            'version' => 1,
            'state' => BillingPlan::STATE_PUBLISHED,
            'localized_content' => ['name' => ['ru' => 'Старт', 'en' => 'Start', 'de' => 'Start']],
            'entitlements' => FeatureCatalog::normalize(['features' => [FeatureCatalog::CHECKOUT_MONITORING], 'limits' => [FeatureCatalog::MAX_ACTIVE_STORES => 1]]),
            'trial_days' => 14,
        ]);
        BillingPrice::query()->create([
            'plan_version_id' => $version->id, 'provider' => 'paddle', 'provider_account_ref' => 'default', 'provider_price_ref' => 'pri_start_v1', 'provider_product_ref' => 'pro_start',
            'price_kind' => 'base', 'mode' => 'test', 'currency' => 'EUR', 'currency_exponent' => 2, 'amount_minor' => 1900, 'billing_interval' => 'month', 'billing_interval_count' => 1,
            'tax_behavior' => 'provider_calculated', 'state' => BillingPlan::STATE_PUBLISHED,
        ]);

        return $version;
    }

    private function signIn(string $email, string $role): void
    {
        $user = User::query()->create(['name' => 'Staff', 'email' => $email, 'password_hash' => Hash::make('staff-password-1234'), 'locale' => 'ru']);
        $mfa = app(MfaService::class);
        $this->secrets[$email] = $mfa->beginSetup($user)['secret'];
        $mfa->confirm($user, $this->code($email));
        PlatformStaff::query()->create(['user_id' => $user->id, 'role' => $role, 'state' => PlatformStaff::STATE_ACTIVE]);

        $this->postJson('/platform-api/v1/auth/login', ['email' => $email, 'password' => 'staff-password-1234'])->assertOk();
        $this->postJson('/platform-api/v1/auth/mfa', ['code' => $this->code($email)])->assertOk();
    }

    private function code(string $email): string
    {
        Carbon::setTestNow(Carbon::now()->addSeconds(30));
        $totp = new Totp;

        return $totp->codeAt($this->secrets[$email], $totp->step(Carbon::now()->getTimestamp()));
    }

    /**
     * @param  array<string, mixed>  $price
     */
    private function price(string $versionId, array $price): TestResponse
    {
        return $this->postJson("/platform-api/v1/plan-versions/{$versionId}/prices", $price);
    }

    private function publish(string $versionId, string $email): TestResponse
    {
        return $this->postJson("/platform-api/v1/plan-versions/{$versionId}/publish", ['reason' => 'Launch', 'code' => $this->code($email)]);
    }
}
