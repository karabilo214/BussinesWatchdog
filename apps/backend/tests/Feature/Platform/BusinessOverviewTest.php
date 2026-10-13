<?php

namespace Tests\Feature\Platform;

use App\Models\BillingCheckoutSession;
use App\Models\BillingDiscount;
use App\Models\BillingPlan;
use App\Models\BillingPlanVersion;
use App\Models\BillingPrice;
use App\Models\BillingRefund;
use App\Models\BillingSubscriptionContract;
use App\Models\BillingTransaction;
use App\Models\PlatformStaff;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Account\MfaService;
use App\Support\Account\Totp;
use App\Support\Billing\FeatureCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class BusinessOverviewTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = '';

    private BillingPlanVersion $version;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20T10:00:00Z');
        $plan = BillingPlan::query()->create(['code' => 'start', 'state' => BillingPlan::STATE_PUBLISHED]);
        $this->version = BillingPlanVersion::query()->create(['plan_id' => $plan->id, 'version' => 1, 'state' => BillingPlan::STATE_PUBLISHED, 'trial_days' => 14, 'localized_content' => ['name' => ['en' => 'Start']], 'entitlements' => FeatureCatalog::normalize(['features' => [], 'limits' => []])]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_overview_counts_customers_contracted_mrr_as_an_exact_fraction_money_and_churn_per_currency_and_mode(): void
    {
        $monthly = $this->price(BillingPrice::KIND_BASE, 'EUR', 1900, 'month');
        $this->price(BillingPrice::KIND_EXTRA_STORE, 'EUR', 500, 'month');
        $this->price(BillingPrice::KIND_FASTER_CHECKS, 'EUR', 700, 'month');
        $yearly = $this->price(BillingPrice::KIND_BASE, 'EUR', 19000, 'year');
        $usd = $this->price(BillingPrice::KIND_BASE, 'USD', 2100, 'month');
        $testPrice = $this->price(BillingPrice::KIND_BASE, 'EUR', 1900, 'month', 'test');

        $discounted = $this->contract($monthly, ['extra_store_quantity' => 2]);
        $this->grant($discounted, null, 1);
        $this->grant($this->contract($monthly, ['normalized_state' => BillingSubscriptionContract::STATE_PAST_DUE]), 3, 3);
        $this->contract($yearly, ['cancel_at_period_end' => true]);
        $this->contract($usd, ['faster_checks' => true]);
        $this->contract($monthly, ['paid_until' => now()->subDay()]);
        $this->contract($monthly, ['normalized_state' => BillingSubscriptionContract::STATE_CANCELLED, 'period_end' => now()->subDays(3), 'created_at' => now()->subDays(60)]);
        $this->contract($monthly, ['normalized_state' => BillingSubscriptionContract::STATE_TRIAL]);
        $this->contract($testPrice, ['mode' => 'test']);
        $trial = $this->tenant();
        Subscription::query()->create(['tenant_id' => $trial->id, 'provider' => 'internal', 'plan_code' => 'start', 'entitlements' => FeatureCatalog::normalize(['features' => [], 'limits' => []]), 'extra_store_quantity' => 0, 'status' => Subscription::STATUS_TRIAL, 'period_start' => now()->subDays(2), 'trial_ends_at' => now()->addDays(5), 'version' => 1]);

        $this->transaction('EUR', 'completed', 2900, 463, now()->subDays(5));
        $this->transaction('EUR', 'paid', 19000, 3034, now()->subDays(10));
        $this->transaction('EUR', 'past_due', 1900, 0, now()->subDays(2));
        $this->transaction('EUR', 'completed', 1900, 300, now()->subDays(45));
        $this->transaction('USD', 'completed', 2100, 0, now()->subDays(1));
        $this->transaction('EUR', 'completed', 999, 0, now()->subDays(1), 'test');
        $this->refund('EUR', 'refunded', 2900, now()->subDays(1));
        $this->refund('EUR', 'pending', 500, now()->subDays(1));

        $this->signIn();
        $overview = $this->getJson('/platform-api/v1/overview?from=2026-09-21&to=2026-10-20')->assertOk()->json();

        $this->assertSame('live', $overview['mode']);
        $this->assertSame(['paying' => 3, 'trial' => 1, 'past_due' => 1, 'cancel_scheduled' => 1], $overview['customers']);
        $this->assertSame([
            ['currency' => 'EUR', 'currency_exponent' => 2, 'numerator_minor' => '13450', 'denominator' => '3', 'contracts' => 2, 'discounted_contracts' => 1, 'unpriced_contracts' => 0],
            ['currency' => 'USD', 'currency_exponent' => 2, 'numerator_minor' => '2100', 'denominator' => '1', 'contracts' => 1, 'discounted_contracts' => 0, 'unpriced_contracts' => 0],
        ], $overview['mrr']);
        $this->assertSame([
            ['currency' => 'EUR', 'currency_exponent' => 2, 'collected_minor' => '21900', 'collected_tax_minor' => '3497', 'collections' => 2, 'refunded_minor' => '2900', 'refunds' => 1, 'net_minor' => '19000'],
            ['currency' => 'USD', 'currency_exponent' => 2, 'collected_minor' => '2100', 'collected_tax_minor' => '0', 'collections' => 1, 'refunded_minor' => '0', 'refunds' => 0, 'net_minor' => '2100'],
        ], $overview['money']);
        $this->assertSame('1900', $overview['money_previous'][0]['collected_minor']);
        $this->assertSame(['ended' => 1, 'at_start' => 6], $overview['churn']);
        $this->assertNotNull($overview['last_synced_at']);

        $test = $this->getJson('/platform-api/v1/overview?mode=test')->assertOk()->json();
        $this->assertSame(1, $test['customers']['paying']);
        $this->assertSame('1900', $test['mrr'][0]['numerator_minor']);
        $this->assertSame('999', $test['money'][0]['collected_minor']);

        $this->getJson('/platform-api/v1/overview?from=2026-10-20&to=2026-10-01')->assertStatus(422)->assertJsonPath('code', 'period_invalid');
        $this->getJson('/platform-api/v1/overview?mode=both')->assertStatus(422);
    }

    public function test_an_empty_ledger_is_unknown_freshness_not_zero_revenue_and_support_cannot_see_it(): void
    {
        $this->signIn();
        $overview = $this->getJson('/platform-api/v1/overview')->assertOk()->json();
        $this->assertNull($overview['last_synced_at']);
        $this->assertSame([], $overview['mrr']);
        $this->assertSame([], $overview['money']);
        $this->assertSame('2026-09-21T00:00:00.000000Z', $overview['from']);

        $this->postJson('/platform-api/v1/auth/logout')->assertNoContent();
        $this->signIn(PlatformStaff::ROLE_SUPPORT, 'support@platform.test');
        $this->getJson('/platform-api/v1/overview')->assertForbidden();
    }

    private function price(string $kind, string $currency, int $amount, string $interval, string $mode = 'live'): BillingPrice
    {
        return BillingPrice::query()->create([
            'plan_version_id' => $this->version->id, 'provider' => 'paddle', 'provider_account_ref' => 'default', 'provider_price_ref' => 'pri_'.Str::random(8),
            'price_kind' => $kind, 'mode' => $mode, 'currency' => $currency, 'currency_exponent' => 2, 'amount_minor' => $amount,
            'billing_interval' => $interval, 'billing_interval_count' => 1, 'tax_behavior' => 'provider_calculated', 'state' => BillingPlan::STATE_PUBLISHED,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function contract(BillingPrice $price, array $overrides = []): BillingSubscriptionContract
    {
        $created = $overrides['created_at'] ?? now()->subDays(40);
        unset($overrides['created_at']);
        $contract = BillingSubscriptionContract::query()->create(array_merge([
            'tenant_id' => $this->tenant()->id, 'plan_version_id' => $this->version->id, 'price_id' => $price->id, 'provider' => 'paddle', 'provider_account_ref' => 'default',
            'provider_subscription_ref' => 'sub_'.Str::random(8), 'mode' => $price->mode, 'normalized_state' => BillingSubscriptionContract::STATE_ACTIVE, 'currency' => $price->currency,
            'extra_store_quantity' => 0, 'faster_checks' => false, 'entitlement_snapshot' => ['features' => [], 'limits' => []], 'period_start' => now()->subDays(5),
            'period_end' => now()->addDays(25), 'paid_until' => now()->addDays(25), 'cancel_at_period_end' => false, 'source_updated_at' => now()->subHour(),
        ], $overrides));
        $contract->forceFill(['created_at' => $created])->save();

        return $contract;
    }

    private function grant(BillingSubscriptionContract $contract, ?int $total, int $used): void
    {
        $user = User::query()->create(['name' => 'Owner', 'email' => Str::random(8).'@shop.test', 'password_hash' => Hash::make(Str::random(20)), 'locale' => 'de']);
        $session = BillingCheckoutSession::query()->create([
            'tenant_id' => $contract->tenant_id, 'actor_user_id' => $user->id, 'plan_version_id' => $contract->plan_version_id, 'price_id' => $contract->price_id, 'extra_store_quantity' => 0,
            'provider' => 'paddle', 'provider_account_ref' => 'default', 'provider_session_ref' => 'txn_'.Str::random(8), 'contract_id' => $contract->id, 'mode' => $contract->mode,
            'status' => BillingCheckoutSession::STATUS_CONFIRMED, 'idempotency_key' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64), 'expires_at' => now()->addDay(),
        ]);
        $discount = BillingDiscount::query()->create([
            'provider' => 'paddle', 'provider_account_ref' => 'default', 'mode' => $contract->mode, 'provider_discount_ref' => 'dsc_'.Str::random(8), 'name' => 'Offer', 'label' => ['en' => 'Offer'],
            'kind' => BillingDiscount::KIND_PERCENTAGE, 'percent_bps' => 1000, 'audience' => BillingDiscount::AUDIENCE_AUTOMATIC, 'duration' => 'forever', 'plan_ids' => [], 'state' => BillingDiscount::STATE_ACTIVE,
        ]);
        DB::table('billing_discount_grants')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $contract->tenant_id, 'checkout_session_id' => $session->id, 'contract_id' => $contract->id, 'discount_id' => $discount->id, 'position' => 1, 'periods_total' => $total, 'periods_used' => $used, 'created_at' => now()]);
    }

    private function transaction(string $currency, string $status, int $total, int $tax, Carbon $billedAt, string $mode = 'live'): BillingTransaction
    {
        return BillingTransaction::query()->create([
            'tenant_id' => $this->tenant()->id, 'provider' => 'paddle', 'provider_account_ref' => 'default', 'mode' => $mode, 'provider_transaction_ref' => 'txn_'.Str::random(8),
            'status' => $status, 'origin' => 'subscription_recurring', 'currency' => $currency, 'currency_exponent' => 2, 'subtotal_minor' => $total - $tax, 'discount_minor' => 0,
            'tax_minor' => $tax, 'total_minor' => $total, 'fee_minor' => 0, 'earnings_minor' => $total - $tax, 'billed_at' => $billedAt, 'source_updated_at' => now()->subHour(),
        ]);
    }

    private function refund(string $currency, string $status, int $amount, Carbon $at): void
    {
        $transaction = $this->transaction($currency, 'completed', $amount, 0, now()->subDays(400));
        BillingRefund::query()->create([
            'tenant_id' => $transaction->tenant_id, 'transaction_id' => $transaction->id, 'mode' => 'live', 'kind' => 'full', 'amount_minor' => $amount, 'currency' => $currency, 'currency_exponent' => 2,
            'reason' => 'Customer asked', 'cancel_subscription' => false, 'status' => $status, 'source_updated_at' => $at,
        ]);
    }

    private function tenant(): Tenant
    {
        return Tenant::query()->create(['name' => 'Shop '.Str::random(5), 'locale' => 'de', 'timezone' => 'Europe/Berlin']);
    }

    private function signIn(string $role = PlatformStaff::ROLE_OWNER, string $email = 'staff@platform.test'): void
    {
        $user = User::query()->create(['name' => 'Staff', 'email' => $email, 'password_hash' => Hash::make('staff-password-1234'), 'locale' => 'ru']);
        $mfa = app(MfaService::class);
        $this->secret = $mfa->beginSetup($user)['secret'];
        $mfa->confirm($user, $this->code());
        PlatformStaff::query()->create(['user_id' => $user->id, 'role' => $role, 'state' => PlatformStaff::STATE_ACTIVE]);
        $this->postJson('/platform-api/v1/auth/login', ['email' => $email, 'password' => 'staff-password-1234'])->assertOk();
        $this->postJson('/platform-api/v1/auth/mfa', ['code' => $this->code()])->assertOk();
    }

    private function code(): string
    {
        Carbon::setTestNow(Carbon::now()->addSeconds(30));
        $totp = new Totp;

        return $totp->codeAt($this->secret, $totp->step(Carbon::now()->getTimestamp()));
    }
}
