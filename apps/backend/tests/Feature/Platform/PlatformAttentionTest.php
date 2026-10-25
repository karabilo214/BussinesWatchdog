<?php

namespace Tests\Feature\Platform;

use App\Models\BillingRefund;
use App\Models\BillingTransaction;
use App\Models\ContactRequest;
use App\Models\PlatformStaff;
use App\Models\Subscription;
use App\Models\SupportAccessGrant;
use App\Models\User;
use App\Support\Account\MfaService;
use App\Support\Account\Totp;
use App\Support\Platform\PlatformCapabilities;
use App\Support\Platform\ServiceHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Notifications\NotificationTestContext;
use Tests\TestCase;

class PlatformAttentionTest extends TestCase
{
    use NotificationTestContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('platform:menu:service-state:v1');
        config(['watchdog.billing.paddle.environment' => 'production']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_one_endpoint_counts_actionable_states_without_customer_data_or_wrong_mode(): void
    {
        $context = $this->context();
        $context['tenant']->forceFill(['name' => 'Secret Shop'])->save();
        $other = $this->context();
        Subscription::query()->create(['tenant_id' => $context['tenant']->id, 'provider' => 'paddle', 'plan_code' => 'pilot', 'entitlements' => [], 'status' => Subscription::STATUS_PAST_DUE]);
        Subscription::query()->create(['tenant_id' => $other['tenant']->id, 'provider' => 'paddle', 'plan_code' => 'pilot', 'entitlements' => [], 'status' => Subscription::STATUS_ACTIVE]);
        ContactRequest::query()->create(['locale' => 'ru', 'name' => 'Private Person', 'email' => 'private@example.test', 'message' => 'Help', 'status' => ContactRequest::STATUS_NEW]);
        ContactRequest::query()->create(['locale' => 'ru', 'name' => 'Other Person', 'email' => 'other@example.test', 'message' => 'Done', 'status' => ContactRequest::STATUS_CLOSED]);
        $transaction = BillingTransaction::query()->create([
            'tenant_id' => $context['tenant']->id, 'provider' => 'paddle', 'provider_account_ref' => 'default', 'mode' => 'live', 'provider_transaction_ref' => 'txn_attention',
            'status' => 'completed', 'currency' => 'EUR', 'currency_exponent' => 2, 'subtotal_minor' => 1000, 'discount_minor' => 0, 'tax_minor' => 0, 'total_minor' => 1000, 'source_updated_at' => now(),
        ]);
        foreach (['live', 'test'] as $mode) {
            BillingRefund::query()->create(['tenant_id' => $context['tenant']->id, 'transaction_id' => $transaction->id, 'mode' => $mode, 'kind' => 'partial', 'amount_minor' => 100, 'currency' => 'EUR', 'currency_exponent' => 2, 'reason' => 'Review', 'status' => BillingRefund::STATUS_REQUESTED]);
        }
        $owner = $this->signIn(PlatformStaff::ROLE_OWNER, []);
        $otherStaff = PlatformStaff::query()->create(['user_id' => User::query()->create(['name' => 'Other Staff', 'email' => 'other-staff@example.test', 'password_hash' => Hash::make('password'), 'locale' => 'ru'])->id, 'role' => PlatformStaff::ROLE_MEMBER, 'capabilities' => ['tenants.support'], 'state' => PlatformStaff::STATE_ACTIVE]);
        foreach ([$owner, $otherStaff] as $staff) {
            SupportAccessGrant::query()->create(['tenant_id' => $context['tenant']->id, 'store_id' => $context['store']->id, 'staff_id' => $staff->id, 'requested_by_staff_id' => $staff->id, 'scopes' => [SupportAccessGrant::READ_DIAGNOSTICS], 'reason' => 'Investigate', 'case_reference' => 'SUP-'.substr($staff->id, 0, 8), 'state' => SupportAccessGrant::PENDING, 'duration_hours' => 24]);
        }
        $this->mock(ServiceHealth::class)->shouldReceive('build')->once()->andReturn(['state' => 'crit']);

        $response = $this->getJson('/platform-api/v1/attention')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $response->assertExactJson(['support_requests_pending' => 1, 'subscriptions_past_due' => 1, 'refunds_requested' => 1, 'contacts_new' => 1, 'service_state' => 'crit']);
        $body = (string) $response->getContent();
        $this->assertStringNotContainsString('Secret Shop', $body);
        $this->assertStringNotContainsString('Private Person', $body);
        $this->assertStringNotContainsString($context['tenant']->id, $body);
    }

    public function test_response_omits_counters_without_permission(): void
    {
        ContactRequest::query()->create(['locale' => 'ru', 'name' => 'Private Person', 'email' => 'private@example.test', 'message' => 'Help', 'status' => ContactRequest::STATUS_NEW]);
        $this->signIn(PlatformStaff::ROLE_MEMBER, [PlatformCapabilities::CONTACTS_READ, PlatformCapabilities::BILLING_READ, PlatformCapabilities::TENANTS_READ]);
        $this->mock(ServiceHealth::class)->shouldNotReceive('build');

        $this->getJson('/platform-api/v1/attention')->assertOk()->assertExactJson(['subscriptions_past_due' => 0, 'contacts_new' => 1]);
    }

    /** @param list<string> $capabilities */
    private function signIn(string $role, array $capabilities): PlatformStaff
    {
        $user = User::query()->create(['name' => 'Staff', 'email' => 'staff@example.test', 'password_hash' => Hash::make('staff-password-1234'), 'locale' => 'ru']);
        $mfa = app(MfaService::class);
        $secret = $mfa->beginSetup($user)['secret'];
        $totp = new Totp;
        $code = $totp->codeAt($secret, $totp->step(now()->getTimestamp()));
        $mfa->confirm($user, $code);
        Carbon::setTestNow(now()->addSeconds(30));
        $code = $totp->codeAt($secret, $totp->step(now()->getTimestamp()));
        $staff = PlatformStaff::query()->create(['user_id' => $user->id, 'role' => $role, 'capabilities' => $capabilities, 'state' => PlatformStaff::STATE_ACTIVE]);
        $this->postJson('/platform-api/v1/auth/login', ['email' => $user->email, 'password' => 'staff-password-1234'])->assertOk();
        $this->postJson('/platform-api/v1/auth/mfa', ['code' => $code])->assertOk();

        return $staff;
    }
}
