<?php

namespace Tests\Feature\Platform;

use App\Mail\NotificationMessageMail;
use App\Models\BillingPlan;
use App\Models\BillingPlanVersion;
use App\Models\BillingPrice;
use App\Models\BillingSubscriptionContract;
use App\Models\EntitlementOverride;
use App\Models\PlatformStaff;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Account\MfaService;
use App\Support\Account\Totp;
use App\Support\Billing\FeatureCatalog;
use App\Support\Billing\Paddle\PaddleSubscriptionSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Notifications\NotificationTestContext;
use Tests\TestCase;

class TenantAdminTest extends TestCase
{
    use NotificationTestContext;
    use RefreshDatabase;

    private const API = 'https://sandbox-api.paddle.com';

    private string $secret = '';

    /** @var array<string, mixed> */
    private array $paddle = ['scheduled_change' => null, 'writes' => []];

    private BillingPlanVersion $version;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-20T10:00:00Z');
        config()->set('watchdog.billing.paddle.environment', 'sandbox');
        config()->set('watchdog.billing.paddle.api_key', 'pdl_sdbx_apikey_test');
        config()->set('watchdog.billing.paddle.client_token', 'test_client_token');
        config()->set('watchdog.billing.paddle.account_ref', 'default');
        config()->set('watchdog.billing.enforcement', 'enforce');
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            if ($request->method() === 'GET' && str_starts_with($request->url(), self::API.'/transactions')) {
                return Http::response(['data' => [], 'meta' => ['pagination' => ['has_more' => false, 'next' => null]]]);
            }

            if ($request->method() !== 'GET') {
                $this->paddle['writes'][] = ['method' => $request->method(), 'url' => $request->url()];
            }

            if ($request->method() === 'POST' && str_ends_with($request->url(), '/subscriptions/sub_1/cancel')) {
                $this->paddle['scheduled_change'] = ['action' => 'cancel', 'effective_at' => '2026-11-11T00:00:00Z'];
            }

            return Http::response(['data' => [
                'id' => 'sub_1',
                'status' => 'active',
                'customer_id' => 'ctm_1',
                'currency_code' => 'EUR',
                'items' => [['price' => ['id' => 'pri_start'], 'quantity' => 1, 'status' => 'active']],
                'current_billing_period' => ['starts_at' => '2026-10-11T00:00:00Z', 'ends_at' => '2026-11-11T00:00:00Z'],
                'next_billed_at' => $this->paddle['scheduled_change'] === null ? '2026-11-11T00:00:00Z' : null,
                'scheduled_change' => $this->paddle['scheduled_change'],
                'updated_at' => '2026-10-20T09:00:0'.count($this->paddle['writes']).'Z',
            ]]);
        });

        $plan = BillingPlan::query()->create(['code' => 'start', 'state' => BillingPlan::STATE_PUBLISHED]);
        $this->version = BillingPlanVersion::query()->create([
            'plan_id' => $plan->id,
            'version' => 1,
            'state' => BillingPlan::STATE_PUBLISHED,
            'trial_days' => 14,
            'localized_content' => ['name' => ['en' => 'Start']],
            'entitlements' => FeatureCatalog::normalize(['features' => [FeatureCatalog::CHECKOUT_MONITORING], 'limits' => [FeatureCatalog::MAX_ACTIVE_STORES => 2, FeatureCatalog::MAX_EXTRA_STORES => 4]]),
        ]);
        BillingPrice::query()->create([
            'plan_version_id' => $this->version->id, 'provider' => 'paddle', 'provider_account_ref' => 'default', 'provider_price_ref' => 'pri_start',
            'price_kind' => BillingPrice::KIND_BASE, 'mode' => 'test', 'currency' => 'EUR', 'currency_exponent' => 2, 'amount_minor' => 1900,
            'billing_interval' => 'month', 'tax_behavior' => 'provider_calculated', 'state' => BillingPlan::STATE_PUBLISHED,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_owner_lists_organisations_with_access_and_billing_apart_and_filters_them(): void
    {
        $paid = $this->paidCustomer('Paid GmbH');
        $trial = $this->context();
        $trial['tenant']->forceFill(['name' => 'Trial AG'])->save();
        $trial['user']->forceFill(['email' => 'trial-owner@example.test', 'email_verified_at' => now()])->save();
        $this->trialFor($trial['tenant'], now()->addDays(3));
        $bare = $this->context();
        $bare['tenant']->forceFill(['name' => 'Bare KG', 'status' => 'suspended'])->save();
        $this->signIn();

        $rows = collect($this->getJson('/platform-api/v1/tenants')->assertOk()->json('data'))->keyBy('name');
        $this->assertSame(['Bare KG', 'Trial AG', 'Paid GmbH'], $rows->keys()->all());
        $this->assertSame('active', $rows['Paid GmbH']['access']['state']);
        $this->assertSame('active', $rows['Paid GmbH']['subscription']['status']);
        $this->assertSame('2026-11-11T00:00:00.000000Z', $rows['Paid GmbH']['subscription']['paid_until']);
        $this->assertSame('trial', $rows['Trial AG']['access']['state']);
        $this->assertSame('paused_manual', $rows['Bare KG']['access']['state']);
        $this->assertNull($rows['Bare KG']['subscription']);
        $this->assertSame('trial-owner@example.test', $rows['Trial AG']['owner']['email']);
        $this->assertSame(['limit' => 2, 'stores' => 1, 'paused' => 0, 'choice_required' => false], $rows['Paid GmbH']['stores']);

        $this->assertSame(['Trial AG'], collect($this->getJson('/platform-api/v1/tenants?subscription=trial')->json('data'))->pluck('name')->all());
        $this->assertSame(['Bare KG'], collect($this->getJson('/platform-api/v1/tenants?subscription=none')->json('data'))->pluck('name')->all());
        $this->assertSame(['Bare KG'], collect($this->getJson('/platform-api/v1/tenants?tenant_status=suspended')->json('data'))->pluck('name')->all());
        $this->assertSame(['Trial AG'], collect($this->getJson('/platform-api/v1/tenants?trial_ends_before='.urlencode(now()->addDays(5)->toJSON()))->json('data'))->pluck('name')->all());
        $this->assertSame(['Trial AG'], collect($this->getJson('/platform-api/v1/tenants?q=TRIAL-owner@example.test')->json('data'))->pluck('name')->all());
        $this->assertSame(['Paid GmbH'], collect($this->getJson('/platform-api/v1/tenants?q='.$paid['tenant']->id)->json('data'))->pluck('name')->all());

        $subscriptions = $this->getJson('/platform-api/v1/subscriptions?status=active')->assertOk()->json('data');
        $this->assertSame(['Paid GmbH'], collect($subscriptions)->pluck('tenant.name')->all());
        $this->assertSame('sub_1', $subscriptions[0]['provider_subscription_ref']);
        $this->assertSame('2026-11-11T00:00:00.000000Z', $subscriptions[0]['next_charge_at']);
    }

    public function test_other_roles_see_less_and_cannot_act(): void
    {
        $paid = $this->paidCustomer('Paid GmbH');
        $paid['user']->forceFill(['email' => 'anna@example.test'])->save();
        $this->signIn(PlatformStaff::ROLE_SUPPORT);

        $row = $this->getJson('/platform-api/v1/tenants')->assertOk()->json('data.0');
        $this->assertSame('a***@example.test', $row['owner']['email']);
        $this->assertNull($row['subscription']);
        $this->assertNull($this->getJson('/platform-api/v1/tenants/'.$paid['tenant']->id)->assertOk()->json('billing'));
        $this->getJson('/platform-api/v1/subscriptions')->assertForbidden();
        $this->postJson('/platform-api/v1/tenants/'.$paid['tenant']->id.'/actions/suspend/preview')->assertForbidden();
        $this->postJson('/platform-api/v1/tenants/'.$paid['tenant']->id.'/actions/suspend', ['reason' => 'Test', 'confirmation_code' => '000000'])->assertForbidden();
        $this->postJson('/platform-api/v1/tenants/'.$paid['tenant']->id.'/reminders/preview', ['topic' => 'payment'])->assertForbidden();
        $this->postJson('/platform-api/v1/tenants/'.$paid['tenant']->id.'/reminders', ['topic' => 'payment'])->assertForbidden();
    }

    public function test_card_shows_billing_history_and_stores_without_details_or_secrets(): void
    {
        $paid = $this->paidCustomer('Paid GmbH');
        $this->signIn();

        $card = $this->getJson('/platform-api/v1/tenants/'.$paid['tenant']->id)
            ->assertOk()
            ->assertJsonPath('billing.subscription.provider_subscription_ref', 'sub_1')
            ->assertJsonPath('billing.contracts.0.state', 'active')
            ->assertJsonPath('billing.contracts.0.plan_code', 'start')
            ->assertJsonPath('members.0.role', 'owner')
            ->assertJsonPath('stores.0.verified', false)
            ->json();
        $this->assertSame(['id', 'name', 'host', 'platform', 'status', 'verified', 'browser_enabled', 'capacity_paused_at', 'open_incidents', 'orders_this_month', 'orders_average', 'orders_over_since', 'created_at'], array_keys($card['stores'][0]));
        $encoded = json_encode($card);
        $this->assertStringNotContainsString('secret', $encoded);
        $this->assertStringNotContainsString('password', $encoded);
        $this->assertStringNotContainsString('api_key', $encoded);
        $this->getJson('/platform-api/v1/tenants/'.fake()->uuid())->assertNotFound();
    }

    public function test_suspend_is_previewed_confirmed_audited_and_leaves_billing_alone(): void
    {
        $paid = $this->paidCustomer('Paid GmbH');
        $tenantId = $paid['tenant']->id;
        $this->signIn();

        $this->postJson("/platform-api/v1/tenants/{$tenantId}/actions/suspend/preview")
            ->assertOk()
            ->assertJsonPath('access_before.state', 'active')
            ->assertJsonPath('access_after.state', 'paused_manual')
            ->assertJsonPath('stores', 1);
        $this->assertSame('active', Tenant::query()->whereKey($tenantId)->value('status'), 'A preview changes nothing.');

        $this->postJson("/platform-api/v1/tenants/{$tenantId}/actions/suspend", ['reason' => 'Abuse report', 'confirmation_code' => '000000'])->assertForbidden()->assertJsonPath('code', 'step_up_failed');
        $this->assertDatabaseHas('platform_audit_log', ['action' => 'tenant.suspend', 'target_tenant_id' => $tenantId, 'result' => 'denied']);

        $subscriptionBefore = Subscription::query()->where('tenant_id', $tenantId)->firstOrFail()->only(['status', 'version', 'cancel_at_period_end']) + ['period_end' => Subscription::query()->where('tenant_id', $tenantId)->firstOrFail()->period_end?->toJSON()];
        $this->act($tenantId, 'suspend', ['reason' => 'Abuse report'])->assertOk()->assertJsonPath('status', 'suspended')->assertJsonPath('access.state', 'paused_manual');
        $this->assertSame($subscriptionBefore, Subscription::query()->where('tenant_id', $tenantId)->firstOrFail()->only(['status', 'version', 'cancel_at_period_end']) + ['period_end' => Subscription::query()->where('tenant_id', $tenantId)->firstOrFail()->period_end?->toJSON()]);
        $this->assertSame([], $this->paddle['writes'], 'Suspension does not touch billing.');
        $audit = DB::table('platform_audit_log')->where('action', 'tenant.suspend')->where('result', 'succeeded')->first();
        $this->assertSame('Abuse report', $audit->reason);
        $changes = json_decode($audit->sanitized_changes, true);
        $this->assertSame(['active', 'paused_manual'], [$changes['access_before'], $changes['access_after']]);
        $this->assertCount(2, $changes);

        $this->act($tenantId, 'suspend', ['reason' => 'Again'])->assertStatus(409)->assertJsonPath('code', 'tenant_not_active');
        $this->act($tenantId, 'resume', ['reason' => 'Resolved'])->assertOk()->assertJsonPath('status', 'active')->assertJsonPath('access.state', 'active');
    }

    public function test_temporary_access_has_an_end_one_at_a_time_and_can_be_revoked(): void
    {
        $customer = $this->context();
        $tenantId = $customer['tenant']->id;
        $this->signIn();

        $this->postJson("/platform-api/v1/tenants/{$tenantId}/actions/grant_override/preview", ['plan_version_id' => $this->version->id, 'ends_at' => now()->addDays(200)->toJSON()])
            ->assertStatus(422)->assertJsonPath('code', 'override_period_invalid');
        $this->postJson("/platform-api/v1/tenants/{$tenantId}/actions/grant_override/preview", ['plan_version_id' => $this->version->id, 'extra_stores' => 1, 'ends_at' => now()->addDays(30)->toJSON()])
            ->assertOk()
            ->assertJsonPath('access_before.state', 'onboarding')
            ->assertJsonPath('access_after.state', 'override')
            ->assertJsonPath('access_after.reasons', ['override_active']);
        $this->assertSame(0, EntitlementOverride::query()->count());

        $card = $this->act($tenantId, 'grant_override', ['plan_version_id' => $this->version->id, 'extra_stores' => 1, 'ends_at' => now()->addDays(30)->toJSON(), 'reason' => 'Pilot partner'])
            ->assertOk()->assertJsonPath('access.state', 'override')->json();
        $this->assertSame(3, $card['billing']['overrides'][0]['entitlements']['limits'][FeatureCatalog::MAX_ACTIVE_STORES]);
        $this->assertTrue($card['billing']['overrides'][0]['active']);
        $this->act($tenantId, 'grant_override', ['plan_version_id' => $this->version->id, 'ends_at' => now()->addDays(10)->toJSON(), 'reason' => 'Twice'])->assertStatus(409)->assertJsonPath('code', 'override_active');

        $this->act($tenantId, 'revoke_override', ['override_id' => $card['billing']['overrides'][0]['id'], 'reason' => 'Pilot over'])->assertOk()->assertJsonPath('access.state', 'onboarding');
        $this->act($tenantId, 'revoke_override', ['override_id' => $card['billing']['overrides'][0]['id'], 'reason' => 'Again'])->assertNotFound()->assertJsonPath('code', 'override_not_found');
    }

    public function test_only_our_own_running_trial_can_be_extended_and_only_forward(): void
    {
        $customer = $this->context();
        $tenantId = $customer['tenant']->id;
        $this->trialFor($customer['tenant'], now()->addDays(2));
        $this->signIn();

        $this->act($tenantId, 'extend_trial', ['trial_ends_at' => now()->addDay()->toJSON(), 'reason' => 'Earlier'])->assertStatus(422)->assertJsonPath('code', 'trial_date_invalid');
        $this->act($tenantId, 'extend_trial', ['trial_ends_at' => now()->addDays(100)->toJSON(), 'reason' => 'Too far'])->assertStatus(422);
        $until = now()->addDays(20)->startOfMinute()->toJSON();
        $this->act($tenantId, 'extend_trial', ['trial_ends_at' => $until, 'reason' => 'Needs more time'])
            ->assertOk()->assertJsonPath('billing.subscription.trial_ends_at', $until);
        $this->assertSame(2, Subscription::query()->where('tenant_id', $tenantId)->value('version'));

        $paid = $this->paidCustomer('Paid GmbH');
        $this->act($paid['tenant']->id, 'extend_trial', ['trial_ends_at' => now()->addDays(20)->toJSON(), 'reason' => 'Paid'])->assertStatus(409)->assertJsonPath('code', 'trial_not_running');
    }

    public function test_cancel_goes_through_the_billing_adapter_and_refresh_reads_paddle_again(): void
    {
        $paid = $this->paidCustomer('Paid GmbH');
        $tenantId = $paid['tenant']->id;
        $this->signIn();

        $this->postJson("/platform-api/v1/tenants/{$tenantId}/actions/cancel_subscription/preview")->assertOk()->assertJsonPath('effective_at', '2026-11-11T00:00:00.000000Z');
        $this->act($tenantId, 'cancel_subscription', ['reason' => 'Customer asked by phone'])
            ->assertOk()
            ->assertJsonPath('billing.subscription.cancel_at_period_end', true)
            ->assertJsonPath('access.state', 'active');
        $this->assertSame(['POST', self::API.'/subscriptions/sub_1/cancel'], array_values($this->paddle['writes'][0]));
        $tenantAudit = DB::table('audit_log')->where('tenant_id', $tenantId)->where('action', 'billing.subscription_cancel_scheduled')->first();
        $this->assertSame('system', $tenantAudit->actor_type);
        $this->assertNull($tenantAudit->actor_user_id);
        $this->assertSame('platform', json_decode($tenantAudit->changes, true)['by']);
        $this->act($tenantId, 'cancel_subscription', ['reason' => 'Twice'])->assertStatus(409)->assertJsonPath('code', 'cancel_already_scheduled');

        $this->paddle['scheduled_change'] = null;
        $this->act($tenantId, 'refresh_billing', ['reason' => 'Customer resumed at Paddle'])->assertOk()->assertJsonPath('billing.subscription.cancel_at_period_end', false);
    }

    /**
     * @return array<string, mixed>
     */
    public function test_owner_reminds_the_organisation_owners_by_email_in_their_language_with_audit(): void
    {
        Mail::fake();
        config()->set('app.url', 'https://watchdog.test');
        $context = $this->context();
        $context['tenant']->forceFill(['name' => 'Kaffee GmbH'])->save();
        $context['user']->forceFill(['email' => 'anna@kaffee.test'])->save();
        $this->context('owner', $context['tenant'])['user']->forceFill(['email' => 'jonas@kaffee.test', 'locale' => 'de'])->save();
        $this->context('owner', $context['tenant'])['user']->forceFill(['email' => 'gone@kaffee.test', 'disabled_at' => now()])->save();
        $this->context('admin', $context['tenant'])['user']->forceFill(['email' => 'admin@kaffee.test'])->save();
        $tenant = $context['tenant']->id;
        $this->signIn();

        $preview = $this->postJson("/platform-api/v1/tenants/{$tenant}/reminders/preview", ['topic' => 'payment'])->assertOk()->json();
        $this->assertSame(['anna@kaffee.test', 'jonas@kaffee.test'], array_column($preview['recipients'], 'email'));
        $this->assertSame(['ru', 'de'], array_column($preview['letters'], 'locale'));
        $this->assertSame('Business Watchdog: напоминание об оплате «Kaffee GmbH»', $preview['letters'][0]['subject']);
        $this->assertStringContainsString('https://watchdog.test/app/settings/billing', $preview['letters'][1]['body']);
        $this->assertStringContainsString('Zahlung', $preview['letters'][1]['body']);
        $this->assertNull($preview['last_sent_at']);
        Mail::assertNothingSent();

        $this->postJson("/platform-api/v1/tenants/{$tenant}/reminders", ['topic' => 'payment'])->assertOk()->assertJsonPath('recipients', 2);
        Mail::assertSent(NotificationMessageMail::class, 2);
        Mail::assertSent(NotificationMessageMail::class, fn (NotificationMessageMail $mail): bool => $mail->hasTo('jonas@kaffee.test') && str_contains($mail->subjectLine, 'Zahlungserinnerung'));
        Mail::assertNotSent(NotificationMessageMail::class, fn (NotificationMessageMail $mail): bool => $mail->hasTo('gone@kaffee.test') || $mail->hasTo('admin@kaffee.test'));

        $this->postJson("/platform-api/v1/tenants/{$tenant}/reminders", ['topic' => 'payment'])->assertStatus(409)->assertJsonPath('code', 'reminder_recently_sent');
        $this->assertNotNull($this->postJson("/platform-api/v1/tenants/{$tenant}/reminders/preview", ['topic' => 'payment'])->json('last_sent_at'));

        $this->postJson("/platform-api/v1/tenants/{$tenant}/reminders", ['topic' => 'other'])->assertStatus(422);
        $this->postJson("/platform-api/v1/tenants/{$tenant}/reminders", ['topic' => 'refund'])->assertStatus(422);
        $this->postJson("/platform-api/v1/tenants/{$tenant}/reminders", ['topic' => 'other', 'subject' => 'Новый отчёт', 'message' => 'Мы добавили отчёт по возвратам, загляните в кабинет.'])->assertOk();
        Mail::assertSent(NotificationMessageMail::class, fn (NotificationMessageMail $mail): bool => $mail->hasTo('anna@kaffee.test') && $mail->subjectLine === 'Business Watchdog: Новый отчёт' && str_contains($mail->bodyText, 'отчёт по возвратам'));

        Carbon::setTestNow(Carbon::now()->addMinutes(11));
        $this->postJson("/platform-api/v1/tenants/{$tenant}/reminders", ['topic' => 'payment'])->assertOk();

        $audit = DB::table('platform_audit_log')->where('action', 'tenant.reminder_sent')->where('target_tenant_id', $tenant)->get();
        $this->assertCount(3, $audit);
        $changes = json_decode((string) $audit->first()->sanitized_changes, true);
        $this->assertSame('payment', $changes['topic']);
        $this->assertCount(2, $changes['recipient_user_ids']);
        $this->assertStringNotContainsString('kaffee.test', (string) $audit->pluck('sanitized_changes')->implode(''));

        $empty = Tenant::query()->create(['name' => 'Empty', 'locale' => 'ru', 'timezone' => 'Europe/Berlin']);
        $this->postJson("/platform-api/v1/tenants/{$empty->id}/reminders", ['topic' => 'subscription'])->assertStatus(409)->assertJsonPath('code', 'no_recipients');
    }

    private function paidCustomer(string $name): array
    {
        $context = $this->context();
        $context['tenant']->forceFill(['name' => $name])->save();
        BillingSubscriptionContract::query()->create([
            'tenant_id' => $context['tenant']->id,
            'plan_version_id' => $this->version->id,
            'provider' => 'paddle',
            'provider_account_ref' => 'default',
            'provider_subscription_ref' => 'sub_1',
            'mode' => 'test',
            'normalized_state' => BillingSubscriptionContract::STATE_ACTIVE,
            'entitlement_snapshot' => ['features' => [], 'limits' => []],
            'source_updated_at' => '2026-10-01T00:00:00Z',
        ]);
        app(PaddleSubscriptionSync::class)->sync('sub_1');
        $this->paddle['writes'] = [];

        return $context;
    }

    private function trialFor(Tenant $tenant, Carbon $endsAt): void
    {
        Subscription::query()->create([
            'tenant_id' => $tenant->id,
            'provider' => 'internal',
            'plan_code' => 'start',
            'plan_version_id' => $this->version->id,
            'entitlements' => FeatureCatalog::normalize($this->version->entitlements),
            'extra_store_quantity' => 0,
            'status' => Subscription::STATUS_TRIAL,
            'period_start' => now()->subDays(10),
            'trial_ends_at' => $endsAt,
            'version' => 1,
        ]);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function act(string $tenantId, string $action, array $body): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/platform-api/v1/tenants/{$tenantId}/actions/{$action}", $body + ['confirmation_code' => $this->code()]);
    }

    private function signIn(string $role = PlatformStaff::ROLE_OWNER): void
    {
        $user = User::query()->create(['name' => 'Staff', 'email' => 'staff@platform.test', 'password_hash' => Hash::make('staff-password-1234'), 'locale' => 'ru']);
        $mfa = app(MfaService::class);
        $this->secret = $mfa->beginSetup($user)['secret'];
        $mfa->confirm($user, $this->code());
        PlatformStaff::query()->create(['user_id' => $user->id, 'role' => $role, 'state' => PlatformStaff::STATE_ACTIVE]);
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
