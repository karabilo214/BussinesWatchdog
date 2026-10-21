<?php

namespace Tests\Feature\Billing;

use App\Models\BillingPlan;
use App\Models\BillingPlanVersion;
use App\Models\BillingPrice;
use App\Models\BillingRefund;
use App\Models\BillingSubscriptionContract;
use App\Models\BillingTransaction;
use App\Models\PlatformStaff;
use App\Models\User;
use App\Support\Account\MfaService;
use App\Support\Account\Totp;
use App\Support\Billing\EntitlementCalculator;
use App\Support\Billing\Paddle\PaddleEventProcessor;
use App\Support\Billing\Paddle\PaddleEventRecorder;
use App\Support\Billing\Paddle\PaddleRefunds;
use App\Support\Billing\SubscriptionProjector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Notifications\NotificationTestContext;
use Tests\TestCase;

class RefundTest extends TestCase
{
    use NotificationTestContext;
    use RefreshDatabase;

    private const API = 'https://sandbox-api.paddle.com';

    /** @var array<string, string> */
    private array $secrets = [];

    /** @var list<array<string, mixed>> */
    private array $sent = [];

    private ?User $customerUser = null;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 12:00:00');
        config()->set('watchdog.billing.paddle.environment', 'sandbox');
        config()->set('watchdog.billing.paddle.api_key', 'pdl_sdbx_apikey_test');
        config()->set('watchdog.billing.paddle.client_token', 'test_client_token');
        config()->set('watchdog.billing.paddle.account_ref', 'default');
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $path = substr($request->url(), strlen(self::API));

            if ($request->method() === 'GET' && str_starts_with($path, '/transactions/txn_1')) {
                return Http::response(['data' => ['id' => 'txn_1', 'details' => ['line_items' => [
                    ['id' => 'txnitm_base', 'totals' => ['total' => '1520']],
                    ['id' => 'txnitm_extra', 'totals' => ['total' => '720']],
                ]]]]);
            }

            if ($request->method() === 'POST' && $path === '/adjustments') {
                $this->sent[] = $request->data();

                return Http::response(['data' => ['id' => 'adj_'.count($this->sent), 'action' => 'refund', 'status' => 'pending_approval', 'transaction_id' => 'txn_1', 'totals' => ['total' => '1000', 'tax' => '160'], 'updated_at' => '2026-10-20T12:00:00Z']], 201);
            }

            if ($request->method() === 'POST' && $path === '/subscriptions/sub_1/cancel') {
                return Http::response(['data' => ['id' => 'sub_1', 'status' => 'active', 'scheduled_change' => ['action' => 'cancel', 'effective_at' => '2026-11-11T00:00:00Z'], 'items' => [], 'updated_at' => '2026-10-20T12:00:01Z']]);
            }

            if ($request->method() === 'GET' && $path === '/subscriptions/sub_1') {
                return Http::response(['data' => ['id' => 'sub_1', 'status' => 'active', 'items' => [], 'updated_at' => '2026-10-20T12:00:01Z']]);
            }

            return Http::response(['error' => ['code' => 'not_found']], 404);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_billing_staff_requests_and_the_owner_approves_a_partial_refund_with_a_code(): void
    {
        $payment = $this->payment();
        $this->signIn('billing@platform.test', PlatformStaff::ROLE_BILLING);

        $this->postJson("/platform-api/v1/payments/{$payment->id}/refunds", ['amount_minor' => '2241', 'reason' => 'Too much'])->assertUnprocessable()->assertJsonPath('code', 'refund_exceeds_payment')->assertJsonPath('details.left_minor', '2240');
        $this->postJson("/platform-api/v1/payments/{$payment->id}/refunds", ['amount_minor' => '12,50', 'reason' => 'Bad amount'])->assertUnprocessable()->assertJsonPath('code', 'refund_amount_invalid');
        $id = $this->postJson("/platform-api/v1/payments/{$payment->id}/refunds", ['amount_minor' => '1700', 'reason' => 'Outage on 18 October'])->assertCreated()->json('id');
        $this->postJson("/platform-api/v1/payments/{$payment->id}/refunds", ['amount_minor' => '600', 'reason' => 'Second one'])->assertUnprocessable()->assertJsonPath('details.left_minor', '540');
        $this->postJson("/platform-api/v1/refunds/{$id}/approve", ['reason' => 'ok', 'confirmation_code' => $this->code('billing@platform.test')])->assertForbidden();
        $this->assertSame([], $this->sent);

        $this->postJson('/platform-api/v1/auth/logout')->assertNoContent();
        $this->signIn('owner@platform.test', PlatformStaff::ROLE_OWNER);
        $this->getJson('/platform-api/v1/refunds?status=requested')->assertOk()->assertJsonPath('data.0.requested_by', 'Staff billing@platform.test')->assertJsonPath('data.0.amount_minor', '1700');
        $this->postJson("/platform-api/v1/refunds/{$id}/approve", ['reason' => 'Approved', 'confirmation_code' => '000000'])->assertForbidden()->assertJsonPath('code', 'step_up_failed');
        $this->assertSame([], $this->sent);

        $this->postJson("/platform-api/v1/refunds/{$id}/approve", ['reason' => 'Approved', 'confirmation_code' => $this->code('owner@platform.test')])
            ->assertOk()->assertJsonPath('status', 'pending')->assertJsonPath('provider_adjustment_ref', 'adj_1');
        $this->assertSame(['action' => 'refund', 'transaction_id' => 'txn_1', 'reason' => 'Outage on 18 October', 'type' => 'partial', 'items' => [
            ['item_id' => 'txnitm_base', 'type' => 'full', 'amount' => '1520'],
            ['item_id' => 'txnitm_extra', 'type' => 'partial', 'amount' => '180'],
        ]], $this->sent[0]);
        $this->postJson("/platform-api/v1/refunds/{$id}/approve", ['reason' => 'Again', 'confirmation_code' => $this->code('owner@platform.test')])->assertConflict()->assertJsonPath('code', 'refund_already_decided');
        $this->assertSame(['billing.refund_requested', 'billing.refund_approved:denied', 'billing.refund_approved:succeeded'], DB::table('platform_audit_log')->where('target_type', 'billing_refund')->orderBy('created_at')->orderBy('id')->get()->map(fn (object $row): string => $row->action.($row->action === 'billing.refund_approved' ? ':'.$row->result : ''))->all());
    }

    public function test_paddle_events_mark_the_refund_done_and_record_refunds_made_in_the_dashboard(): void
    {
        $payment = $this->payment();
        config()->set('watchdog.billing.enforcement', 'enforce');
        BillingSubscriptionContract::query()->where('provider_subscription_ref', 'sub_1')->update(['period_end' => now()->addMonth(), 'paid_until' => now()->addMonth()]);
        app(SubscriptionProjector::class)->project($payment->tenant_id, 'test');
        $this->assertSame('active', app(EntitlementCalculator::class)->forTenant($payment->tenant_id)->access);
        $owner = $this->signIn('owner@platform.test', PlatformStaff::ROLE_OWNER);
        $refund = app(PaddleRefunds::class)->request($owner->id, $payment->id, '1000', 'Goodwill', false);
        app(PaddleRefunds::class)->approve($owner->id, $refund->id, 'ok');

        $this->event('evt_1', 'adjustment.updated', ['id' => 'adj_1', 'action' => 'refund', 'status' => 'approved', 'transaction_id' => 'txn_1', 'currency_code' => 'EUR', 'totals' => ['total' => '1000', 'tax' => '160'], 'updated_at' => '2026-10-20T12:05:00Z']);
        $this->event('evt_2', 'adjustment.created', ['id' => 'adj_dash', 'action' => 'refund', 'type' => 'partial', 'status' => 'approved', 'transaction_id' => 'txn_1', 'reason' => 'From the dashboard', 'currency_code' => 'EUR', 'totals' => ['total' => '240', 'tax' => '38'], 'updated_at' => '2026-10-20T12:06:00Z']);
        $this->event('evt_3', 'adjustment.created', ['id' => 'adj_credit', 'action' => 'credit', 'status' => 'approved', 'transaction_id' => 'txn_1', 'totals' => ['total' => '100'], 'updated_at' => '2026-10-20T12:07:00Z']);
        app(PaddleEventProcessor::class)->processDue();

        app(EntitlementCalculator::class)->forget();
        $this->assertSame('paused_billing', app(EntitlementCalculator::class)->forTenant($payment->tenant_id)->access);
        $this->assertNotNull(BillingSubscriptionContract::query()->where('provider_subscription_ref', 'sub_1')->value('access_revoked_at'));
        $this->assertTrue(collect(Http::recorded())->contains(fn (array $pair): bool => $pair[0]->url() === self::API.'/subscriptions/sub_1/cancel' && $pair[0]->data() === ['effective_from' => 'immediately']));

        $this->assertSame([BillingRefund::STATUS_REFUNDED, 160], [$refund->fresh()->status, $refund->fresh()->tax_minor]);
        $dashboard = BillingRefund::query()->where('provider_adjustment_ref', 'adj_dash')->sole();
        $this->assertSame([240, 'From the dashboard', null, BillingRefund::STATUS_REFUNDED], [$dashboard->amount_minor, $dashboard->reason, $dashboard->requested_by, $dashboard->status]);
        $this->assertSame(2, BillingRefund::query()->count());

        $this->getJson('/platform-api/v1/payments')->assertOk()->assertJsonPath('data.0.refunded_minor', '1240')->assertJsonPath('month.0.refunded_minor', '1240');
        $this->actingAs($this->customerUser)->withSession(['active_tenant_id' => $payment->tenant_id])->getJson('/api/v1/billing/invoices')->assertOk()->assertJsonPath('data.0.refunded_minor', '1240');
    }

    public function test_a_rejected_request_frees_the_amount_and_a_confirmed_refund_cancels_immediately(): void
    {
        $payment = $this->payment();
        $owner = $this->signIn('owner@platform.test', PlatformStaff::ROLE_OWNER);
        $refunds = app(PaddleRefunds::class);

        $first = $refunds->request($owner->id, $payment->id, '2240', 'Whole payment', false);
        $this->assertSame('full', $first->kind);
        $refunds->reject($owner->id, $first->id, 'Not agreed');
        $this->assertSame(BillingRefund::STATUS_REJECTED, $first->fresh()->status);

        $second = $refunds->request($owner->id, $payment->id, '2240', 'Leaving us', true);
        $refunds->approve($owner->id, $second->id, 'Agreed');

        $this->assertSame(['type' => 'full'], array_intersect_key($this->sent[0], ['type' => true]));
        $this->assertArrayNotHasKey('items', $this->sent[0]);
        $this->assertFalse(collect(Http::recorded())->contains(fn (array $pair): bool => $pair[0]->url() === self::API.'/subscriptions/sub_1/cancel'));
        app(PaddleRefunds::class)->project(['id' => 'adj_1', 'action' => 'refund', 'status' => 'approved', 'transaction_id' => 'txn_1', 'totals' => ['total' => '2240'], 'updated_at' => '2026-10-20T12:05:00Z']);
        $this->assertTrue(collect(Http::recorded())->contains(fn (array $pair): bool => $pair[0]->url() === self::API.'/subscriptions/sub_1/cancel' && $pair[0]->data() === ['effective_from' => 'immediately']));
        $this->assertNotNull(BillingSubscriptionContract::query()->where('provider_subscription_ref', 'sub_1')->value('access_revoked_at'));
    }

    private function payment(): BillingTransaction
    {
        $context = $this->context();
        $this->customerUser = $context['user'];
        $plan = BillingPlan::query()->create(['code' => 'start', 'state' => 'published']);
        $version = BillingPlanVersion::query()->create(['plan_id' => $plan->id, 'version' => 1, 'state' => 'published', 'localized_content' => ['name' => ['en' => 'Start']], 'entitlements' => ['features' => [], 'limits' => []]]);
        $price = BillingPrice::query()->create(['plan_version_id' => $version->id, 'provider' => 'paddle', 'provider_account_ref' => 'default', 'provider_price_ref' => 'pri_start', 'price_kind' => 'base', 'mode' => 'test', 'currency' => 'EUR', 'currency_exponent' => 2, 'amount_minor' => 2800, 'billing_interval' => 'month', 'tax_behavior' => 'provider_calculated', 'state' => 'published']);
        $contract = BillingSubscriptionContract::query()->create([
            'tenant_id' => $context['tenant']->id, 'provider' => 'paddle', 'provider_account_ref' => 'default', 'provider_subscription_ref' => 'sub_1', 'mode' => 'test',
            'normalized_state' => BillingSubscriptionContract::STATE_ACTIVE, 'plan_version_id' => $version->id, 'price_id' => $price->id, 'entitlement_snapshot' => ['features' => [], 'limits' => []], 'source_updated_at' => now(),
        ]);

        return BillingTransaction::query()->create([
            'tenant_id' => $context['tenant']->id, 'contract_id' => $contract->id, 'provider' => 'paddle', 'provider_account_ref' => 'default', 'mode' => 'test',
            'provider_transaction_ref' => 'txn_1', 'provider_subscription_ref' => 'sub_1', 'status' => 'completed', 'currency' => 'EUR', 'currency_exponent' => 2,
            'subtotal_minor' => 2353, 'discount_minor' => 470, 'tax_minor' => 357, 'total_minor' => 2240, 'fee_minor' => 157, 'earnings_minor' => 1726,
            'billed_at' => now()->subDays(2), 'source_updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function event(string $ref, string $type, array $data): void
    {
        app(PaddleEventRecorder::class)->record(['event_id' => $ref, 'event_type' => $type, 'occurred_at' => $data['updated_at'], 'data' => $data], 'webhook');
    }

    private function signIn(string $email, string $role): PlatformStaff
    {
        $user = User::query()->create(['name' => 'Staff '.$email, 'email' => $email, 'password_hash' => Hash::make('staff-password-1234'), 'locale' => 'ru']);
        $mfa = app(MfaService::class);
        $this->secrets[$email] = $mfa->beginSetup($user)['secret'];
        $mfa->confirm($user, $this->code($email));
        $staff = PlatformStaff::query()->create(['user_id' => $user->id, 'role' => $role, 'state' => PlatformStaff::STATE_ACTIVE]);
        $this->postJson('/platform-api/v1/auth/login', ['email' => $email, 'password' => 'staff-password-1234'])->assertOk();
        $this->postJson('/platform-api/v1/auth/mfa', ['code' => $this->code($email)])->assertOk();

        return $staff;
    }

    private function code(string $email): string
    {
        Carbon::setTestNow(Carbon::now()->addSeconds(30));
        $totp = new Totp;

        return $totp->codeAt($this->secrets[$email], $totp->step(Carbon::now()->getTimestamp()));
    }
}
