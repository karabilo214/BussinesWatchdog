<?php

namespace Tests\Feature\Billing;

use App\Models\BillingEventInbox;
use App\Models\BillingSubscriptionContract;
use App\Models\BillingTransaction;
use App\Models\PlatformStaff;
use App\Models\User;
use App\Support\Account\MfaService;
use App\Support\Account\Totp;
use App\Support\Billing\Paddle\PaddleEventProcessor;
use App\Support\Billing\Paddle\PaddleEventRecorder;
use App\Support\Billing\Paddle\PaddleTransactions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Notifications\NotificationTestContext;
use Tests\TestCase;

class BillingTransactionsTest extends TestCase
{
    use NotificationTestContext;
    use RefreshDatabase;

    private const API = 'https://sandbox-api.paddle.com';

    private string $secret = '';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 12:00:00');
        config()->set('watchdog.billing.paddle.environment', 'sandbox');
        config()->set('watchdog.billing.paddle.api_key', 'pdl_sdbx_apikey_test');
        config()->set('watchdog.billing.paddle.client_token', 'test_client_token');
        config()->set('watchdog.billing.paddle.account_ref', 'default');
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_transactions_are_recorded_from_events_keeping_the_newest_state(): void
    {
        $context = $this->context();
        $this->recordEvent('evt_1', 'transaction.completed', $this->transaction($context['tenant']->id, 'txn_1', 'completed', '2026-10-11T12:05:00Z'));
        $this->recordEvent('evt_0', 'transaction.billed', $this->transaction($context['tenant']->id, 'txn_1', 'billed', '2026-10-11T12:00:00Z'));
        $this->recordEvent('evt_x', 'transaction.completed', $this->transaction((string) Str::uuid(), 'txn_foreign', 'completed', '2026-10-11T12:00:00Z'));

        $summary = app(PaddleEventProcessor::class)->processDue();

        $this->assertSame(3, $summary['processed']);
        $row = BillingTransaction::query()->sole();
        $this->assertSame(['txn_1', 'completed', 'INV-1', 'EUR', 2], [$row->provider_transaction_ref, $row->status, $row->invoice_number, $row->currency, $row->currency_exponent]);
        $this->assertSame([2300, 400, 437, 2737, 186, 2551], [$row->subtotal_minor, $row->discount_minor, $row->tax_minor, $row->total_minor, $row->fee_minor, $row->earnings_minor]);
        $this->assertSame('2026-11-11T00:00:00+00:00', $row->period_end->toAtomString());
        $this->assertSame($context['tenant']->id, $row->tenant_id);
    }

    public function test_the_transactions_of_a_subscription_are_read_again_page_by_page(): void
    {
        $context = $this->context();
        $this->contract($context['tenant']->id);
        Http::fake([
            self::API.'/transactions*' => function (Request $request) use ($context) {
                $after = $request->data()['after'] ?? null;

                return Http::response($after === null
                    ? ['data' => [$this->transaction($context['tenant']->id, 'txn_1', 'completed', '2026-09-11T12:00:00Z', 'sub_1')], 'meta' => ['pagination' => ['has_more' => true, 'next' => self::API.'/transactions?after=txn_1&subscription_id=sub_1']]]
                    : ['data' => [$this->transaction($context['tenant']->id, 'txn_2', 'past_due', '2026-10-11T12:00:00Z', 'sub_1')], 'meta' => ['pagination' => ['has_more' => false, 'next' => null]]]);
            },
        ]);

        $this->artisan('billing:paddle-transactions')->expectsOutput('subscriptions=1 transactions=2')->assertSuccessful();

        $this->assertSame(['txn_1', 'txn_2'], BillingTransaction::query()->orderBy('provider_transaction_ref')->pluck('provider_transaction_ref')->all());
        $this->assertNotNull(BillingTransaction::query()->where('provider_transaction_ref', 'txn_2')->value('contract_id'));
    }

    public function test_the_owner_lists_invoices_downloads_a_pdf_link_and_changes_the_payment_method(): void
    {
        $context = $this->context();
        $other = $this->context();
        $this->contract($context['tenant']->id);
        $paid = $this->stored($context['tenant']->id, 'txn_1', 'completed');
        $draft = $this->stored($context['tenant']->id, 'txn_draft', 'draft');
        $canceled = $this->stored($context['tenant']->id, 'txn_cancel', 'canceled');
        $foreign = $this->stored($other['tenant']->id, 'txn_foreign', 'completed');
        Http::fake([
            self::API.'/transactions/txn_1/invoice*' => Http::response(['data' => ['url' => 'https://sandbox-invoice.paddle.com/inv_1.pdf?sig=abc']]),
            self::API.'/subscriptions/sub_1/update-payment-method-transaction' => Http::response(['data' => ['id' => 'txn_pm', 'status' => 'ready']]),
        ]);
        $api = fn () => $this->actingAs($context['user'])->withSession(['active_tenant_id' => $context['tenant']->id]);

        $list = $api()->getJson('/api/v1/billing/invoices')->assertOk()->json('data');
        $this->assertSame([$canceled->id, $paid->id], array_column($list, 'id'));
        $this->assertSame(['2737', '437', true], [$list[1]['total_minor'], $list[1]['tax_minor'], $list[1]['invoice_available']]);
        $this->assertFalse($list[0]['invoice_available']);

        $api()->getJson("/api/v1/billing/invoices/{$paid->id}/download")->assertOk()->assertJsonPath('url', 'https://sandbox-invoice.paddle.com/inv_1.pdf?sig=abc');
        $api()->getJson("/api/v1/billing/invoices/{$canceled->id}/download")->assertStatus(409)->assertJsonPath('code', 'invoice_not_available');
        $api()->getJson("/api/v1/billing/invoices/{$foreign->id}/download")->assertNotFound();
        $api()->getJson("/api/v1/billing/invoices/{$draft->id}/download")->assertStatus(409);

        $api()->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/billing/payment-method')
            ->assertOk()->assertJsonPath('provider_checkout.transaction_id', 'txn_pm')->assertJsonPath('provider_checkout.client_token', 'test_client_token');
        $this->actingAs($other['user'])->withSession(['active_tenant_id' => $other['tenant']->id])->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/billing/payment-method')->assertStatus(409)->assertJsonPath('code', 'no_active_subscription');

        $member = $this->context('viewer', $context['tenant']);
        $this->actingAs($member['user'])->withSession(['active_tenant_id' => $context['tenant']->id])->getJson('/api/v1/billing/invoices')->assertForbidden();
    }

    public function test_the_platform_owner_sees_every_payment_and_the_month_per_currency(): void
    {
        $first = $this->context();
        $second = $this->context();
        $this->stored($first['tenant']->id, 'txn_1', 'completed');
        $this->stored($second['tenant']->id, 'txn_2', 'paid');
        $this->stored($second['tenant']->id, 'txn_3', 'completed', ['currency' => 'USD', 'total_minor' => 1900, 'tax_minor' => 0, 'fee_minor' => null, 'earnings_minor' => null]);
        $this->stored($second['tenant']->id, 'txn_4', 'past_due');
        $this->stored($first['tenant']->id, 'txn_old', 'completed', ['billed_at' => now()->subMonths(2)]);

        $this->signIn(PlatformStaff::ROLE_OWNER);
        $response = $this->getJson('/platform-api/v1/payments')->assertOk();
        $this->assertCount(5, $response->json('data'));
        $this->assertSame($second['tenant']->name, $response->json('data.1.tenant.name'));
        $this->assertSame([
            ['currency' => 'EUR', 'currency_exponent' => 2, 'payments' => 2, 'total_minor' => '5474', 'tax_minor' => '874', 'fee_minor' => '372', 'earnings_minor' => '5102', 'refunded_minor' => '0'],
            ['currency' => 'USD', 'currency_exponent' => 2, 'payments' => 1, 'total_minor' => '1900', 'tax_minor' => '0', 'fee_minor' => null, 'earnings_minor' => null, 'refunded_minor' => '0'],
        ], $response->json('month'));
        $this->assertCount(1, $this->getJson('/platform-api/v1/payments?status=past_due')->json('data'));
        $this->getJson("/platform-api/v1/tenants/{$first['tenant']->id}")->assertOk()->assertJsonPath('billing.transactions.0.provider_transaction_ref', 'txn_old');
    }

    public function test_support_cannot_read_payments(): void
    {
        $this->signIn(PlatformStaff::ROLE_SUPPORT);
        $this->getJson('/platform-api/v1/payments')->assertForbidden();
    }

    /**
     * @return array<string, mixed>
     */
    private function transaction(string $tenantId, string $ref, string $status, string $updatedAt, ?string $subscription = null): array
    {
        return [
            'id' => $ref, 'status' => $status, 'origin' => 'subscription_recurring', 'customer_id' => 'ctm_1', 'currency_code' => 'EUR', 'subscription_id' => $subscription,
            'invoice_number' => 'INV-1', 'billed_at' => '2026-10-11T12:00:00Z', 'created_at' => '2026-10-11T11:59:00Z', 'updated_at' => $updatedAt,
            'custom_data' => ['tenant_id' => $tenantId], 'billing_period' => ['starts_at' => '2026-10-11T00:00:00Z', 'ends_at' => '2026-11-11T00:00:00Z'],
            'details' => ['totals' => ['subtotal' => '2300', 'discount' => '400', 'tax' => '437', 'total' => '2737', 'grand_total' => '2737', 'fee' => '186', 'earnings' => '2551', 'currency_code' => 'EUR']],
            'customer' => ['email' => 'buyer@example.com'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function recordEvent(string $eventRef, string $type, array $data): void
    {
        app(PaddleEventRecorder::class)->record(['event_id' => $eventRef, 'event_type' => $type, 'occurred_at' => $data['updated_at'], 'data' => $data], 'webhook');
        $this->assertStringNotContainsString('buyer@example.com', json_encode(BillingEventInbox::query()->where('provider_event_ref', $eventRef)->value('sanitized_payload')));
    }

    private function contract(string $tenantId): BillingSubscriptionContract
    {
        return BillingSubscriptionContract::query()->create([
            'tenant_id' => $tenantId, 'provider' => 'paddle', 'provider_account_ref' => 'default', 'provider_subscription_ref' => 'sub_1', 'mode' => 'test',
            'normalized_state' => BillingSubscriptionContract::STATE_ACTIVE, 'entitlement_snapshot' => ['features' => [], 'limits' => []], 'source_updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function stored(string $tenantId, string $ref, string $status, array $overrides = []): BillingTransaction
    {
        return BillingTransaction::query()->create(array_merge([
            'tenant_id' => $tenantId, 'provider' => 'paddle', 'provider_account_ref' => 'default', 'mode' => 'test', 'provider_transaction_ref' => $ref,
            'status' => $status, 'origin' => 'subscription_recurring', 'currency' => 'EUR', 'currency_exponent' => 2, 'subtotal_minor' => 2300, 'discount_minor' => 400,
            'tax_minor' => 437, 'total_minor' => 2737, 'fee_minor' => 186, 'earnings_minor' => 2551, 'billed_at' => now()->subDays(3), 'source_updated_at' => now(),
        ], $overrides));
    }

    private function signIn(string $role): void
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
