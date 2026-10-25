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
use App\Support\Billing\EntitlementCalculator;
use App\Support\Billing\FeatureCatalog;
use App\Support\Billing\Paddle\PaddleSubscriptionSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Notifications\NotificationTestContext;
use Tests\TestCase;

class TenantAdminTest extends TestCase
{
    use NotificationTestContext;
    use RefreshDatabase;

    private const API = 'https://sandbox-api.paddle.com';

    private string $secret = '';

    /** @var array<string, mixed> */
    private array $paddle = ['scheduled_change' => null, 'cancelled' => false, 'writes' => []];

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
                $this->paddle['cancelled'] = ($request->data()['effective_from'] ?? null) === 'immediately';
            }

            return Http::response(['data' => [
                'id' => 'sub_1',
                'status' => $this->paddle['cancelled'] ? 'canceled' : 'active',
                'customer_id' => 'ctm_1',
                'currency_code' => 'EUR',
                'items' => [['price' => ['id' => 'pri_start'], 'quantity' => 1, 'status' => 'active']],
                'current_billing_period' => $this->paddle['cancelled'] ? null : ['starts_at' => '2026-10-11T00:00:00Z', 'ends_at' => '2026-11-11T00:00:00Z'],
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
        $this->postJson('/platform-api/v1/tenants/'.$paid['tenant']->id.'/invitations', ['email' => 'x@example.test', 'role' => 'viewer', 'reason' => 'Test'])->assertForbidden();
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

        $this->postJson("/platform-api/v1/tenants/{$tenantId}/actions/cancel_subscription/preview")->assertOk()->assertJsonPath('access_after.state', 'active');
        $this->act($tenantId, 'cancel_subscription', ['reason' => 'Customer asked by phone'])
            ->assertOk()
            ->assertJsonPath('billing.subscription.status', 'cancelled')
            ->assertJsonPath('access.state', 'active');
        $this->assertSame(['POST', self::API.'/subscriptions/sub_1/cancel'], array_values($this->paddle['writes'][0]));
        $this->assertSame('cancelled', Subscription::query()->where('tenant_id', $tenantId)->value('status'));
        $this->assertSame('active', app(EntitlementCalculator::class)->forTenant($tenantId)->access);
        $tenantAudit = DB::table('audit_log')->where('tenant_id', $tenantId)->where('action', 'billing.subscription_cancelled')->first();
        $this->assertSame('system', $tenantAudit->actor_type);
        $this->assertNull($tenantAudit->actor_user_id);
        $this->assertSame('platform', json_decode($tenantAudit->changes, true)['by']);
        $this->act($tenantId, 'cancel_subscription', ['reason' => 'Twice'])->assertStatus(409)->assertJsonPath('code', 'no_active_subscription');

        $this->act($tenantId, 'refresh_billing', ['reason' => 'Check Paddle status'])->assertOk()->assertJsonPath('billing.subscription.status', 'cancelled');
        Carbon::setTestNow('2026-11-11T00:00:01Z');
        app(EntitlementCalculator::class)->forget();
        $this->assertSame('paused_billing', app(EntitlementCalculator::class)->forTenant($tenantId)->access);
    }

    /**
     * @return array<string, mixed>
     */
    public function test_owner_reminds_chosen_members_with_edited_letters_saves_templates_and_keeps_every_letter(): void
    {
        Mail::fake();
        config()->set('app.url', 'https://watchdog.test');
        $context = $this->context();
        $context['tenant']->forceFill(['name' => 'Kaffee GmbH'])->save();
        $anna = $context['user']->forceFill(['email' => 'anna@kaffee.test']);
        $anna->save();
        $jonas = $this->context('owner', $context['tenant'])['user']->forceFill(['email' => 'jonas@kaffee.test', 'locale' => 'de']);
        $jonas->save();
        $gone = $this->context('owner', $context['tenant'])['user']->forceFill(['email' => 'gone@kaffee.test', 'disabled_at' => now()]);
        $gone->save();
        $admin = $this->context('admin', $context['tenant'])['user']->forceFill(['email' => 'admin@kaffee.test', 'locale' => 'en']);
        $admin->save();
        $tenant = $context['tenant']->id;
        $this->signIn();

        $preview = $this->postJson("/platform-api/v1/tenants/{$tenant}/reminders/preview", ['topic' => 'payment'])->assertOk()->json();
        $this->assertSame(['admin@kaffee.test', 'anna@kaffee.test', 'gone@kaffee.test', 'jonas@kaffee.test'], array_column($preview['members'], 'email'));
        $this->assertFalse(collect($preview['members'])->firstWhere('email', 'gone@kaffee.test')['active']);
        $this->assertEqualsCanonicalizing([$anna->id, $jonas->id], $preview['suggested_recipient_ids']);
        $this->assertSame(['ru', 'de'], array_column($preview['letters'], 'locale'));
        $this->assertSame('Business Watchdog: напоминание об оплате «:tenant»', $preview['templates']['ru']['subject']);
        $this->assertFalse($preview['templates']['ru']['customized']);
        $this->assertSame('Business Watchdog: напоминание об оплате «Kaffee GmbH»', $preview['letters'][0]['subject']);
        $this->assertStringContainsString('https://watchdog.test/app/settings/billing', $preview['letters'][1]['body']);
        $this->assertSame([':tenant', ':link'], $preview['placeholders']);
        Mail::assertNothingSent();

        $chosen = $this->postJson("/platform-api/v1/tenants/{$tenant}/reminders/preview", ['topic' => 'payment', 'recipient_ids' => [$admin->id, $jonas->id], 'letters' => ['en' => ['subject' => 'Invoice for :tenant', 'body' => 'Please check the invoice: :link']]])->assertOk()->json();
        $this->assertSame(['en', 'de'], array_column($chosen['letters'], 'locale'));
        $this->assertSame('Invoice for Kaffee GmbH', $chosen['letters'][0]['subject']);

        $letters = ['en' => ['subject' => 'Invoice for :tenant', 'body' => 'Please check the invoice: :link'], 'de' => ['subject' => 'Rechnung für :tenant', 'body' => 'Bitte prüfen Sie die Rechnung: :link']];
        $this->postJson("/platform-api/v1/tenants/{$tenant}/reminders", ['topic' => 'payment', 'recipient_ids' => [$admin->id], 'letters' => ['de' => $letters['de']]])->assertStatus(422)->assertJsonPath('code', 'letter_invalid')->assertJsonPath('details.locale', 'en');
        $this->postJson("/platform-api/v1/tenants/{$tenant}/reminders", ['topic' => 'payment', 'recipient_ids' => [$gone->id], 'letters' => $letters])->assertStatus(422)->assertJsonPath('code', 'recipients_invalid');
        $this->postJson("/platform-api/v1/tenants/{$tenant}/reminders", ['topic' => 'payment', 'recipient_ids' => [(string) Str::uuid()], 'letters' => $letters])->assertStatus(422)->assertJsonPath('code', 'recipients_invalid');
        $this->postJson("/platform-api/v1/tenants/{$tenant}/reminders", ['topic' => 'refund', 'recipient_ids' => [$admin->id], 'letters' => $letters])->assertStatus(422);
        Mail::assertNothingSent();

        $sent = $this->postJson("/platform-api/v1/tenants/{$tenant}/reminders", ['topic' => 'payment', 'recipient_ids' => [$admin->id, $jonas->id], 'letters' => $letters])->assertCreated()->json();
        $this->assertSame('payment', $sent['topic']);
        $this->assertSame('staff@platform.test', $sent['sender']['email']);
        $this->assertSame([['email' => 'admin@kaffee.test', 'locale' => 'en', 'subject' => 'Invoice for Kaffee GmbH', 'body' => 'Please check the invoice: https://watchdog.test/app/settings/billing'], ['email' => 'jonas@kaffee.test', 'locale' => 'de', 'subject' => 'Rechnung für Kaffee GmbH', 'body' => 'Bitte prüfen Sie die Rechnung: https://watchdog.test/app/settings/billing']], $sent['messages']);
        Mail::assertSent(NotificationMessageMail::class, 2);
        Mail::assertSent(NotificationMessageMail::class, fn (NotificationMessageMail $mail): bool => $mail->hasTo('jonas@kaffee.test') && $mail->subjectLine === 'Rechnung für Kaffee GmbH');
        Mail::assertNotSent(NotificationMessageMail::class, fn (NotificationMessageMail $mail): bool => $mail->hasTo('anna@kaffee.test'));

        $this->postJson("/platform-api/v1/tenants/{$tenant}/reminders", ['topic' => 'payment', 'recipient_ids' => [$admin->id], 'letters' => $letters])->assertStatus(409)->assertJsonPath('code', 'reminder_recently_sent');
        $this->assertNotNull($this->postJson("/platform-api/v1/tenants/{$tenant}/reminders/preview", ['topic' => 'payment'])->json('last_sent_at'));

        $this->putJson('/platform-api/v1/reminder-templates/payment/de', ['subject' => 'x', 'body' => 'too short'])->assertStatus(422)->assertJsonPath('code', 'letter_invalid');
        $this->putJson('/platform-api/v1/reminder-templates/payment/de', $letters['de'])->assertOk()->assertJsonPath('customized', true)->assertJsonPath('subject', 'Rechnung für :tenant');
        $this->assertSame('Rechnung für :tenant', $this->postJson("/platform-api/v1/tenants/{$tenant}/reminders/preview", ['topic' => 'payment'])->json('templates.de.subject'));
        $this->putJson('/platform-api/v1/reminder-templates/refund/de', $letters['de'])->assertNotFound();
        $template = json_decode((string) DB::table('platform_audit_log')->where('action', 'reminder.template_saved')->value('sanitized_changes'), true);
        $this->assertSame('Rechnung für :tenant', $template['after']['subject']);

        Carbon::setTestNow(Carbon::now()->addMinutes(11));
        $other = $this->postJson("/platform-api/v1/tenants/{$tenant}/reminders/preview", ['topic' => 'other', 'recipient_ids' => [$anna->id]])->assertOk()->json();
        $this->assertSame('Business Watchdog:', trim($other['templates']['ru']['subject']));
        $this->postJson("/platform-api/v1/tenants/{$tenant}/reminders", ['topic' => 'other', 'recipient_ids' => [$anna->id], 'letters' => ['ru' => ['subject' => 'Business Watchdog: новый отчёт', 'body' => "Здравствуйте!\n\nМы добавили отчёт по возвратам.\n\n:link"]]])->assertCreated();

        $history = $this->getJson("/platform-api/v1/tenants/{$tenant}/reminders")->assertOk()->json('data');
        $this->assertSame(['other', 'payment'], array_column($history, 'topic'));
        $this->assertSame('anna@kaffee.test', $history[0]['messages'][0]['email']);
        $this->assertStringContainsString('отчёт по возвратам', $history[0]['messages'][0]['body']);

        $audit = DB::table('platform_audit_log')->where('action', 'tenant.reminder_sent')->where('target_tenant_id', $tenant)->orderBy('created_at')->get();
        $this->assertCount(2, $audit);
        $changes = json_decode((string) $audit->first()->sanitized_changes, true);
        $this->assertSame(['admin@kaffee.test', 'jonas@kaffee.test'], $changes['recipients']);
        $this->assertSame($sent['id'], $changes['reminder_id']);

        $empty = Tenant::query()->create(['name' => 'Empty', 'locale' => 'ru', 'timezone' => 'Europe/Berlin']);
        $this->postJson("/platform-api/v1/tenants/{$empty->id}/reminders", ['topic' => 'subscription', 'recipient_ids' => [$anna->id], 'letters' => $letters])->assertStatus(409)->assertJsonPath('code', 'no_recipients');
    }

    public function test_staff_change_roles_and_remove_members_of_the_customer_team_but_never_the_owner(): void
    {
        $context = $this->context();
        $tenant = $context['tenant']->id;
        $owner = $context['user'];
        $operator = $this->context('operator', $context['tenant'])['user'];
        $viewer = $this->context('viewer', $context['tenant'])['user'];
        $stranger = $this->context()['user'];
        $this->signIn();

        $this->patchJson("/platform-api/v1/tenants/{$tenant}/members/{$operator->id}", ['role' => 'admin'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->patchJson("/platform-api/v1/tenants/{$tenant}/members/{$operator->id}", ['role' => 'owner', 'reason' => 'Asked'])->assertStatus(409)->assertJsonPath('code', 'owner_role_protected');
        $this->patchJson("/platform-api/v1/tenants/{$tenant}/members/{$owner->id}", ['role' => 'viewer', 'reason' => 'Asked'])->assertStatus(409)->assertJsonPath('code', 'owner_role_protected');
        $this->patchJson("/platform-api/v1/tenants/{$tenant}/members/{$operator->id}", ['role' => 'root', 'reason' => 'Asked'])->assertStatus(422)->assertJsonPath('code', 'role_not_assignable');
        $this->patchJson("/platform-api/v1/tenants/{$tenant}/members/{$stranger->id}", ['role' => 'admin', 'reason' => 'Asked'])->assertNotFound();
        $this->patchJson("/platform-api/v1/tenants/{$tenant}/members/{$operator->id}", ['role' => 'admin', 'reason' => 'Customer asked by email'])->assertOk()->assertJsonPath('role', 'admin');
        $this->assertSame('admin', DB::table('memberships')->where('tenant_id', $tenant)->where('user_id', $operator->id)->value('role'));

        $this->postJson("/platform-api/v1/tenants/{$tenant}/members/{$owner->id}/remove", ['reason' => 'Asked'])->assertStatus(409)->assertJsonPath('code', 'owner_role_protected');
        $this->postJson("/platform-api/v1/tenants/{$tenant}/members/{$viewer->id}/remove", ['reason' => 'Left the company'])->assertNoContent();
        $this->assertFalse(DB::table('memberships')->where('tenant_id', $tenant)->where('user_id', $viewer->id)->exists());
        $this->postJson("/platform-api/v1/tenants/{$tenant}/members/{$viewer->id}/remove", ['reason' => 'Twice'])->assertNotFound();

        $this->assertSame('Customer asked by email', DB::table('platform_audit_log')->where('action', 'tenant.member_role_changed')->value('reason'));
        $this->assertSame('Left the company', DB::table('platform_audit_log')->where('action', 'tenant.member_removed')->value('reason'));
        $removed = json_decode((string) DB::table('audit_log')->where('tenant_id', $tenant)->where('action', 'membership.removed')->value('changes'), true);
        $this->assertSame('platform', $removed['by']);
        $this->assertSame('viewer', $removed['role']);
        $this->assertNull(DB::table('audit_log')->where('tenant_id', $tenant)->where('action', 'membership.role_changed')->value('actor_user_id'));
    }

    public function test_owner_invites_and_revokes_team_members_of_the_organisation_with_a_reason(): void
    {
        Mail::fake();
        $context = $this->context();
        $context['user']->forceFill(['email' => 'anna@kaffee.test'])->save();
        $context['tenant']->forceFill(['name' => 'Kaffee GmbH', 'locale' => 'de'])->save();
        $tenant = $context['tenant']->id;
        $this->signIn();

        $this->postJson("/platform-api/v1/tenants/{$tenant}/invitations", ['email' => 'jonas@kaffee.test', 'role' => 'operator'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/platform-api/v1/tenants/{$tenant}/invitations", ['email' => 'jonas@kaffee.test', 'role' => 'owner', 'reason' => 'Asked by phone'])->assertStatus(422);
        $this->postJson("/platform-api/v1/tenants/{$tenant}/invitations", ['email' => 'Anna@Kaffee.test', 'role' => 'admin', 'reason' => 'Asked by phone'])->assertStatus(409)->assertJsonPath('code', 'already_member');
        $invitation = $this->postJson("/platform-api/v1/tenants/{$tenant}/invitations", ['email' => ' Jonas@Kaffee.test ', 'role' => 'operator', 'reason' => 'Asked by phone'])
            ->assertCreated()->assertJsonPath('email', 'jonas@kaffee.test')->assertJsonPath('role', 'operator')->json();

        Mail::assertSent(NotificationMessageMail::class, fn (NotificationMessageMail $mail): bool => $mail->hasTo('jonas@kaffee.test') && str_contains($mail->bodyText, 'Das Business-Watchdog-Team') && str_contains($mail->bodyText, '/app/invitation?token='));
        $this->assertSame([$invitation['id']], array_column($this->getJson("/platform-api/v1/tenants/{$tenant}/invitations")->assertOk()->json('data'), 'id'));
        $this->assertDatabaseHas('audit_log', ['tenant_id' => $tenant, 'action' => 'invitation.created', 'actor_type' => 'system', 'actor_user_id' => null]);
        $this->assertSame('Asked by phone', DB::table('platform_audit_log')->where('action', 'tenant.member_invited')->value('reason'));

        $this->postJson("/platform-api/v1/tenants/{$tenant}/invitations/{$invitation['id']}/revoke", [])->assertStatus(422);
        $this->postJson("/platform-api/v1/tenants/{$tenant}/invitations/{$invitation['id']}/revoke", ['reason' => 'Wrong address'])->assertOk()->assertJsonPath('id', $invitation['id']);
        $this->assertSame([], $this->getJson("/platform-api/v1/tenants/{$tenant}/invitations")->json('data'));
        $this->assertDatabaseHas('audit_log', ['tenant_id' => $tenant, 'action' => 'invitation.revoked', 'actor_type' => 'system']);
        $this->assertSame(1, DB::table('platform_audit_log')->where('action', 'tenant.invitation_revoked')->where('target_tenant_id', $tenant)->count());

        $other = $this->context();
        $this->postJson("/platform-api/v1/tenants/{$other['tenant']->id}/invitations/{$invitation['id']}/revoke", ['reason' => 'Wrong tenant'])->assertNotFound();
    }

    public function test_only_the_service_owner_resets_customer_mfa_with_step_up_and_scoped_audit(): void
    {
        Mail::fake();
        $context = $this->context();
        $tenantId = $context['tenant']->id;
        $target = $context['user'];
        $target->forceFill(['locale' => 'de'])->save();
        $mfa = app(MfaService::class);
        $secret = $mfa->beginSetup($target)['secret'];
        $mfa->confirm($target, (new Totp)->codeAt($secret, (new Totp)->step(now()->getTimestamp())));
        DB::table('sessions')->insert(['id' => 'customer-device', 'user_id' => $target->id, 'payload' => '', 'last_activity' => time()]);
        $this->signIn();

        $path = "/platform-api/v1/tenants/{$tenantId}/members/{$target->id}/mfa/reset";
        $this->postJson($path, ['reason' => 'Customer called'])->assertStatus(422)->assertJsonValidationErrors('confirmation_code');
        $this->postJson($path, ['reason' => 'Customer called', 'confirmation_code' => '000000'])->assertForbidden()->assertJsonPath('code', 'step_up_failed');
        $this->assertNotNull($target->refresh()->mfa_confirmed_at);
        $this->postJson($path, ['reason' => 'Customer called', 'confirmation_code' => $this->code()])->assertNoContent();

        $this->assertNull($target->refresh()->mfa_confirmed_at);
        $this->assertDatabaseMissing('sessions', ['id' => 'customer-device']);
        $this->assertDatabaseHas('audit_log', ['tenant_id' => $tenantId, 'action' => 'user.mfa_reset', 'entity_id' => $target->id]);
        $this->assertSame('Customer called', DB::table('platform_audit_log')->where('action', 'tenant.member_mfa_reset')->where('result', 'succeeded')->value('reason'));
        Mail::assertSent(NotificationMessageMail::class, fn (NotificationMessageMail $mail): bool => $mail->hasTo($target->email) && str_contains($mail->bodyText, 'Alle aktiven Sitzungen'));

        $other = $this->context();
        $this->postJson("/platform-api/v1/tenants/{$other['tenant']->id}/members/{$target->id}/mfa/reset", ['reason' => 'Wrong tenant', 'confirmation_code' => $this->code()])
            ->assertNotFound();
    }

    public function test_support_sends_a_customer_member_the_password_reset_link(): void
    {
        Mail::fake();
        $context = $this->context();
        $tenantId = $context['tenant']->id;
        $target = $context['user'];
        $this->signIn(PlatformStaff::ROLE_SUPPORT);
        $path = "/platform-api/v1/tenants/{$tenantId}/members/{$target->id}/password-reset";

        $this->postJson("/platform-api/v1/tenants/{$tenantId}/members/{$target->id}/mfa/reset", ['reason' => 'Customer called', 'confirmation_code' => $this->code()])
            ->assertForbidden()->assertJsonPath('code', 'owner_only');
        $this->assertDatabaseHas('platform_audit_log', ['action' => 'tenant.member_mfa_reset', 'result' => 'denied']);
        $this->postJson($path, [])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson($path, ['reason' => 'Customer lost the password'])->assertStatus(202);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $target->email]);
        Mail::assertSent(NotificationMessageMail::class, fn (NotificationMessageMail $mail): bool => $mail->hasTo($target->email));
        $this->assertSame('Customer lost the password', DB::table('platform_audit_log')->where('action', 'tenant.member_password_reset_sent')->where('target_id', $target->id)->value('reason'));

        $other = $this->context();
        $this->postJson("/platform-api/v1/tenants/{$other['tenant']->id}/members/{$target->id}/password-reset", ['reason' => 'Wrong tenant'])->assertNotFound();
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
    private function act(string $tenantId, string $action, array $body): TestResponse
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
