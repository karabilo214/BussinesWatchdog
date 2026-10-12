<?php

namespace Tests\Feature\Platform;

use App\Models\BillingCheckoutSession;
use App\Models\BillingDiscount;
use App\Models\BillingPlan;
use App\Models\BillingPlanVersion;
use App\Models\BillingPrice;
use App\Models\BillingSubscriptionContract;
use App\Models\PlatformStaff;
use App\Models\User;
use App\Support\Account\MfaService;
use App\Support\Account\Totp;
use App\Support\Billing\DiscountLedger;
use App\Support\Billing\FeatureCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Notifications\NotificationTestContext;
use Tests\TestCase;

class DiscountTest extends TestCase
{
    use NotificationTestContext;
    use RefreshDatabase;

    private const API = 'https://sandbox-api.paddle.com';

    private string $secret = '';

    private int $discountSequence = 0;

    private bool $paddleDown = false;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-12T10:00:00Z');
        config()->set('watchdog.billing.paddle.environment', 'sandbox');
        config()->set('watchdog.billing.paddle.api_key', 'pdl_sdbx_apikey_test');
        config()->set('watchdog.billing.paddle.client_token', 'test_client_token');
        config()->set('watchdog.billing.paddle.account_ref', 'default');
        Http::fake([
            self::API.'/discounts' => fn () => $this->paddleDown ? Http::response(['error' => ['code' => 'internal_error']], 500) : Http::response(['data' => ['id' => 'dsc_'.(++$this->discountSequence)]], 201),
            self::API.'/discounts/*' => Http::response(['data' => ['id' => 'dsc_x', 'status' => 'archived']]),
            self::API.'/transactions' => fn () => Http::response(['data' => ['id' => 'txn_'.Str::random(8), 'status' => 'draft']], 201),
            self::API.'/subscriptions/*' => Http::response(['data' => ['id' => 'sub_1']]),
        ]);
    }

    public function test_owner_creates_a_promo_code_and_paddle_gets_its_terms(): void
    {
        $start = $this->plan('start', 1900);
        $this->signIn();

        $this->discount(['code' => 'launch20'], '000000')->assertForbidden()->assertJsonPath('code', 'step_up_failed');
        $created = $this->discount(['code' => 'launch20', 'plan_ids' => [$start->plan_id], 'usage_limit' => 2, 'expires_at' => '2026-11-01T00:00:00Z'])
            ->assertCreated()
            ->assertJsonPath('code', 'LAUNCH20')
            ->assertJsonPath('percent', '20')
            ->assertJsonPath('duration', 'periods')
            ->assertJsonPath('duration_periods', 3)
            ->assertJsonPath('used', 0)
            ->json();

        Http::assertSent(fn (Request $request): bool => $request->url() === self::API.'/discounts' && $request->method() === 'POST'
            && $request['type'] === 'percentage' && $request['amount'] === '20' && $request['recur'] === true && $request['maximum_recurring_intervals'] === 3
            && $request['usage_limit'] === 2 && $request['enabled_for_checkout'] === false && $request['expires_at'] === '2026-11-01T00:00:00Z');
        $this->discount(['code' => 'LAUNCH20'])->assertStatus(409)->assertJsonPath('code', 'discount_code_taken');
        $this->discount(['code' => 'x'])->assertStatus(422)->assertJsonPath('code', 'discount_code_invalid_format');
        $this->discount(['code' => 'BIG', 'percent_bps' => 10001])->assertStatus(422)->assertJsonPath('code', 'discount_amount_invalid');
        $this->discount(['code' => 'FLAT5', 'kind' => 'flat', 'percent_bps' => null, 'amount_minor' => '500', 'currency' => 'EUR', 'duration' => 'once', 'duration_periods' => null])
            ->assertCreated()->assertJsonPath('amount_minor', '500')->assertJsonPath('currency', 'EUR');
        Http::assertSent(fn (Request $request): bool => $request->url() === self::API.'/discounts' && $request['type'] === 'flat' && $request['amount'] === '500' && $request['currency_code'] === 'EUR' && $request['recur'] === false);

        $this->patchJson("/platform-api/v1/discounts/{$created['id']}", ['usage_limit' => 5, 'reason' => 'More', 'confirmation_code' => $this->code()])->assertOk()->assertJsonPath('usage_limit', 5);
        $this->postJson("/platform-api/v1/discounts/{$created['id']}/archive", ['reason' => 'Done', 'confirmation_code' => $this->code()])->assertOk()->assertJsonPath('state', 'archived');
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH' && $request->url() === self::API.'/discounts/dsc_1' && ($request->data()['status'] ?? null) === 'archived');

        $this->assertSame(['discount.created:denied', 'discount.created', 'discount.created', 'discount.updated', 'discount.archived'], DB::table('platform_audit_log')->where('action', 'like', 'discount%')->orderBy('id')->get()->map(fn ($row) => $row->result === 'succeeded' ? $row->action : $row->action.':'.$row->result)->all());
    }

    public function test_customer_uses_a_promo_code_only_where_it_applies_and_until_it_is_used_up(): void
    {
        $start = $this->plan('start', 1900);
        $pro = $this->plan('pro', 4900);
        $code = $this->stored(['audience' => 'code', 'code' => 'LAUNCH20', 'plan_ids' => [$start->plan_id], 'usage_limit' => 1]);
        $customer = $this->context();

        $this->check($customer, 'launch20', $start)->assertOk()->assertJsonPath('percent', '20')->assertJsonPath('position', 3);
        $this->check($customer, 'NOPE', $start)->assertStatus(422)->assertJsonPath('code', 'discount_code_invalid');
        $this->check($customer, 'LAUNCH20', $pro)->assertStatus(422)->assertJsonPath('code', 'discount_not_applicable');

        $this->checkout($customer, $start, 'launch20')
            ->assertStatus(202)
            ->assertJsonPath('discounts.amount_before_minor', '1900')
            ->assertJsonPath('discounts.saving_minor', '380')
            ->assertJsonPath('discounts.amount_after_minor', '1520')
            ->assertJsonPath('discounts.lines.0.discount.id', $code->id)
            ->assertJsonPath('discounts.lines.0.saving_minor', '380');
        Http::assertSent(fn (Request $request): bool => $request->url() === self::API.'/discounts' && $request['type'] === 'flat' && $request['amount'] === '380'
            && $request['currency_code'] === 'EUR' && $request['recur'] === false && $request['usage_limit'] === 1);
        Http::assertSent(fn (Request $request): bool => $request->url() === self::API.'/transactions' && $request['discount_id'] === 'dsc_1');
        BillingCheckoutSession::query()->update(['status' => BillingCheckoutSession::STATUS_CONFIRMED]);

        $this->check($this->context(), 'LAUNCH20', $start)->assertStatus(409)->assertJsonPath('code', 'discount_used_up');
        $code->forceFill(['usage_limit' => null, 'expires_at' => now()->subMinute()])->save();
        $this->check($customer, 'LAUNCH20', $start)->assertStatus(422)->assertJsonPath('code', 'discount_expired');
    }

    public function test_offer_then_personal_discount_then_promo_code_apply_one_after_another(): void
    {
        $start = $this->plan('start', 1900, 900);
        $vip = $this->context();
        $other = $this->context();
        $this->stored(['audience' => 'automatic', 'kind' => 'percentage', 'percent_bps' => 1000]);
        $offer = $this->stored(['audience' => 'automatic', 'kind' => 'flat', 'percent_bps' => null, 'amount_minor' => 500, 'currency' => 'EUR', 'currency_exponent' => 2, 'duration' => 'forever', 'duration_periods' => null]);
        $this->stored(['audience' => 'automatic', 'percent_bps' => 9000, 'expires_at' => now()->subDay()]);
        $this->stored(['audience' => 'automatic', 'percent_bps' => 9000, 'starts_at' => now()->addDay()]);
        $personal = $this->stored(['audience' => 'tenant', 'percent_bps' => 1500]);
        $personal->tenants()->sync([$vip['tenant']->id]);
        $code = $this->stored(['audience' => 'code', 'code' => 'EXTRA', 'kind' => 'flat', 'percent_bps' => null, 'amount_minor' => 300, 'currency' => 'EUR', 'currency_exponent' => 2, 'duration' => 'once', 'duration_periods' => null]);

        $this->plans($vip)->assertJsonPath('data.0.discounts.0.id', $offer->id)->assertJsonPath('data.0.discounts.1.id', $personal->id);
        $this->plans($other)->assertJsonCount(1, 'data.0.discounts')->assertJsonPath('data.0.discounts.0.amount_minor', '500');

        $response = $this->checkout($vip, $start, 'extra', 1)->assertStatus(202);
        $this->assertSame(
            [[$offer->id, '2800', '500', '2300'], [$personal->id, '2300', '345', '1955'], [$code->id, '1955', '300', '1655']],
            collect($response->json('discounts.lines'))->map(fn (array $line): array => [$line['discount']['id'], $line['amount_before_minor'], $line['saving_minor'], $line['amount_after_minor']])->all(),
        );
        $this->assertSame(['2800', '1145', '1655'], [$response->json('discounts.amount_before_minor'), $response->json('discounts.saving_minor'), $response->json('discounts.amount_after_minor')]);
        Http::assertSent(fn (Request $request): bool => $request->url() === self::API.'/discounts' && $request['amount'] === '1145');

        $this->checkout($other, $start)->assertStatus(202)->assertJsonPath('discounts.saving_minor', '500');
        $personal->forceFill(['state' => BillingDiscount::STATE_ARCHIVED])->save();
        $this->plans($vip)->assertJsonCount(1, 'data.0.discounts');
    }

    public function test_each_renewal_gets_the_discounts_still_running_and_every_period_is_recorded(): void
    {
        $start = $this->plan('start', 1900, 900);
        $customer = $this->context();
        $offer = $this->stored(['audience' => 'automatic', 'kind' => 'percentage', 'percent_bps' => 1000, 'duration' => 'forever', 'duration_periods' => null]);
        $personal = $this->stored(['audience' => 'tenant', 'percent_bps' => 2000, 'duration' => 'periods', 'duration_periods' => 2]);
        $personal->tenants()->sync([$customer['tenant']->id]);
        $this->stored(['audience' => 'code', 'code' => 'FIRST', 'kind' => 'flat', 'percent_bps' => null, 'amount_minor' => 500, 'currency' => 'EUR', 'currency_exponent' => 2, 'duration' => 'once', 'duration_periods' => null]);
        $this->checkout($customer, $start, 'first', 2)->assertStatus(202);
        $session = BillingCheckoutSession::query()->sole();
        $session->forceFill(['status' => BillingCheckoutSession::STATUS_CONFIRMED])->save();
        $contract = $this->contract($customer, $start, 2, '2026-10-12T10:00:00Z', '2026-11-12T10:00:00Z');
        app(DiscountLedger::class)->linkContract($session, $contract);

        $this->artisan('billing:plan-discounts')->expectsOutput('planned=1 failed=0')->assertSuccessful();
        $this->artisan('billing:plan-discounts')->expectsOutput('planned=0 failed=0')->assertSuccessful();

        $second = DB::table('billing_discount_applications')->where('period_number', 2)->sole();
        $this->assertSame([3700, 1036, 2664, 'applied'], [(int) $second->amount_before_minor, (int) $second->saving_minor, (int) $second->amount_after_minor, $second->status]);
        $this->assertSame([$offer->id, $personal->id], DB::table('billing_discount_application_lines')->where('application_id', $second->id)->orderBy('position')->pluck('discount_id')->all());
        Http::assertSent(fn (Request $request): bool => $request->url() === self::API.'/discounts' && $request['amount'] === '1036' && $request['recur'] === true && $request['maximum_recurring_intervals'] === 1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH' && $request->url() === self::API.'/subscriptions/sub_1'
            && ($request->data()['discount']['effective_from'] ?? null) === 'next_billing_period');

        $contract->forceFill(['period_start' => '2026-11-12T10:00:00Z', 'period_end' => '2026-12-12T10:00:00Z'])->save();
        $this->paddleDown = true;
        $this->artisan('billing:plan-discounts')->expectsOutput('planned=0 failed=1')->assertSuccessful();
        $this->assertSame('failed', DB::table('billing_discount_applications')->where('period_number', 3)->value('status'));

        $this->paddleDown = false;
        $this->artisan('billing:plan-discounts')->expectsOutput('planned=1 failed=0')->assertSuccessful();
        $third = DB::table('billing_discount_applications')->where('period_number', 3)->sole();
        $this->assertSame([370, 'applied', 'dsc_3'], [(int) $third->saving_minor, $third->status, $third->provider_discount_ref]);
        $this->assertSame([$offer->id], DB::table('billing_discount_application_lines')->where('application_id', $third->id)->pluck('discount_id')->all());

        $this->signIn();
        $history = $this->getJson('/platform-api/v1/discount-applications')->assertOk()->json('data');
        $this->assertSame([3, 2, 1], array_column($history, 'period_number'));
        $this->assertSame(['FIRST'], array_values(array_filter(array_map(fn (array $line): ?string => $line['discount']['code'], $history[2]['lines']))));
    }

    public function test_staff_find_organisations_to_target(): void
    {
        $customer = $this->context();
        $customer['tenant']->forceFill(['name' => 'Kaffeerösterei Lindner'])->save();
        $this->signIn();

        $this->getJson('/platform-api/v1/tenants/lookup?query=lindner')->assertOk()->assertJsonPath('data.0.id', $customer['tenant']->id);
        $this->getJson('/platform-api/v1/tenants/lookup?query='.$customer['tenant']->id)->assertOk()->assertJsonPath('data.0.name', 'Kaffeerösterei Lindner');
        $this->getJson('/platform-api/v1/discounts')->assertOk()->assertJsonPath('data', []);
    }

    private function plan(string $code, int $amount, ?int $extraAmount = null): BillingPlanVersion
    {
        $plan = BillingPlan::query()->create(['code' => $code, 'state' => BillingPlan::STATE_PUBLISHED]);
        $version = BillingPlanVersion::query()->create([
            'plan_id' => $plan->id, 'version' => 1, 'state' => BillingPlan::STATE_PUBLISHED,
            'localized_content' => ['name' => ['en' => ucfirst($code)]],
            'entitlements' => FeatureCatalog::normalize(['features' => [], 'limits' => [FeatureCatalog::MAX_ACTIVE_STORES => 1]]),
        ]);
        BillingPrice::query()->create([
            'plan_version_id' => $version->id, 'provider' => 'paddle', 'provider_account_ref' => 'default', 'provider_price_ref' => 'pri_'.$code,
            'price_kind' => 'base', 'mode' => 'test', 'currency' => 'EUR', 'currency_exponent' => 2, 'amount_minor' => $amount, 'billing_interval' => 'month',
            'billing_interval_count' => 1, 'tax_behavior' => 'provider_calculated', 'state' => BillingPlan::STATE_PUBLISHED,
        ]);

        if ($extraAmount !== null) {
            BillingPrice::query()->create([
                'plan_version_id' => $version->id, 'provider' => 'paddle', 'provider_account_ref' => 'default', 'provider_price_ref' => 'pri_'.$code.'_extra',
                'price_kind' => 'extra_store', 'mode' => 'test', 'currency' => 'EUR', 'currency_exponent' => 2, 'amount_minor' => $extraAmount, 'billing_interval' => 'month',
                'billing_interval_count' => 1, 'tax_behavior' => 'provider_calculated', 'state' => BillingPlan::STATE_PUBLISHED,
            ]);
        }

        return $version;
    }

    /**
     * @param  array<string, mixed>  $customer
     */
    private function contract(array $customer, BillingPlanVersion $version, int $extraStores, string $periodStart, string $periodEnd): BillingSubscriptionContract
    {
        return BillingSubscriptionContract::query()->create([
            'tenant_id' => $customer['tenant']->id, 'plan_version_id' => $version->id, 'price_id' => BillingPrice::query()->where('plan_version_id', $version->id)->where('price_kind', 'base')->value('id'),
            'provider' => 'paddle', 'provider_account_ref' => 'default', 'provider_subscription_ref' => 'sub_1', 'mode' => 'test', 'normalized_state' => 'active', 'currency' => 'EUR',
            'extra_store_quantity' => $extraStores, 'entitlement_snapshot' => ['features' => [], 'limits' => []], 'period_start' => $periodStart, 'period_end' => $periodEnd,
            'paid_until' => $periodEnd, 'source_updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function stored(array $attributes): BillingDiscount
    {
        return BillingDiscount::query()->create($attributes + [
            'provider' => 'paddle', 'provider_account_ref' => 'default', 'mode' => 'test', 'provider_discount_ref' => 'dsc_'.Str::random(8),
            'name' => 'Offer', 'label' => ['en' => 'Offer'], 'kind' => 'percentage', 'percent_bps' => 2000, 'audience' => 'automatic',
            'duration' => 'periods', 'duration_periods' => 3, 'plan_ids' => [], 'state' => BillingDiscount::STATE_ACTIVE,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function discount(array $overrides, ?string $confirmation = null): TestResponse
    {
        return $this->postJson('/platform-api/v1/discounts', $overrides + [
            'name' => 'Launch', 'label' => ['ru' => 'Скидка на запуск', 'en' => 'Launch offer'], 'audience' => 'code', 'kind' => 'percentage', 'percent_bps' => 2000,
            'duration' => 'periods', 'duration_periods' => 3, 'reason' => 'Launch', 'confirmation_code' => $confirmation ?? $this->code(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $customer
     */
    private function check(array $customer, string $code, BillingPlanVersion $version): TestResponse
    {
        $priceId = BillingPrice::query()->where('plan_version_id', $version->id)->value('id');

        return $this->actingAs($customer['user'])->withSession(['active_tenant_id' => $customer['tenant']->id])
            ->getJson('/api/v1/billing/discounts/check?'.http_build_query(['code' => $code, 'price_id' => $priceId]));
    }

    /**
     * @param  array<string, mixed>  $customer
     */
    private function checkout(array $customer, BillingPlanVersion $version, ?string $code = null, int $extraStores = 0): TestResponse
    {
        return $this->actingAs($customer['user'])->withSession(['active_tenant_id' => $customer['tenant']->id])
            ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/billing/checkout', array_filter(['price_id' => BillingPrice::query()->where('plan_version_id', $version->id)->where('price_kind', 'base')->value('id'), 'discount_code' => $code, 'extra_stores' => $extraStores]));
    }

    /**
     * @param  array<string, mixed>  $customer
     */
    private function plans(array $customer): TestResponse
    {
        return $this->actingAs($customer['user'])->withSession(['active_tenant_id' => $customer['tenant']->id])->getJson('/api/v1/billing/plans')->assertOk();
    }

    private function signIn(): void
    {
        $user = User::query()->create(['name' => 'Owner', 'email' => 'owner@example.test', 'password_hash' => Hash::make('owner-password-1234'), 'locale' => 'ru']);
        $mfa = app(MfaService::class);
        $this->secret = $mfa->beginSetup($user)['secret'];
        $mfa->confirm($user, $this->code());
        PlatformStaff::query()->create(['user_id' => $user->id, 'role' => PlatformStaff::ROLE_OWNER, 'state' => PlatformStaff::STATE_ACTIVE]);
        $this->postJson('/platform-api/v1/auth/login', ['email' => 'owner@example.test', 'password' => 'owner-password-1234'])->assertOk();
        $this->postJson('/platform-api/v1/auth/mfa', ['code' => $this->code()])->assertOk();
    }

    private function code(): string
    {
        Carbon::setTestNow(Carbon::now()->addSeconds(30));
        $totp = new Totp;

        return $totp->codeAt($this->secret, $totp->step(Carbon::now()->getTimestamp()));
    }
}
