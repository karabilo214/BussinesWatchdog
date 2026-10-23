<?php

namespace Tests\Feature\Platform;

use App\Mail\NotificationMessageMail;
use App\Models\PlatformStaff;
use App\Models\SupportAccessGrant;
use App\Models\User;
use App\Support\Account\MfaService;
use App\Support\Account\Totp;
use App\Support\Platform\SupportGrants;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Notifications\NotificationTestContext;
use Tests\TestCase;

class SupportGrantTest extends TestCase
{
    use NotificationTestContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-23T10:00:00Z');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_request_approval_scope_expiry_and_revocation_guard_each_request(): void
    {
        $customer = $this->context();
        $other = $this->context();
        $staff = $this->signInStaff();
        Mail::fake();
        $tenant = $customer['tenant']->id;
        $store = $customer['store']->id;
        $detail = "/platform-api/v1/tenants/{$tenant}/stores/{$store}";

        $this->getJson($detail)->assertForbidden();
        $request = ['tenant_id' => $tenant, 'store_id' => $store, 'scopes' => ['read_diagnostics'], 'reason' => 'Investigate case 42'];
        $this->postJson('/platform-api/v1/support-grants', [...$request, 'case_reference' => 'MANUAL-42'])->assertStatus(422)->assertJsonValidationErrors('case_reference');
        $grantId = $this->postJson('/platform-api/v1/support-grants', $request)->assertCreated()->assertJsonPath('state', 'pending')->assertJsonPath('duration_hours', 24)->assertJsonPath('case_reference', 'SUP-000001')->json('id');
        $this->postJson('/platform-api/v1/support-grants', $request)->assertCreated()->assertJsonPath('id', $grantId);
        $this->assertSame(1, SupportAccessGrant::query()->count());
        $this->assertSame(2, DB::table('support_case_counters')->where('name', 'support_access')->value('next_number'));
        Mail::assertSent(NotificationMessageMail::class, 1);
        Mail::assertSent(NotificationMessageMail::class, fn (NotificationMessageMail $mail): bool => str_contains($mail->bodyText, 'SUP-000001') && str_contains($mail->bodyText, '/app/settings/support'));
        $second = app(SupportGrants::class)->request($other['tenant']->id, $other['store']->id, $staff, ['read_diagnostics'], 24, 'Investigate another store', null);
        $this->assertSame('SUP-000002', $second->case_reference);
        $this->getJson($detail)->assertForbidden();

        $this->actingAs($other['user'])->withSession(['active_tenant_id' => $other['tenant']->id])
            ->postJson("/api/v1/support-grants/{$grantId}/approve")->assertNotFound();
        $this->actingAs($customer['user'])->withSession(['active_tenant_id' => $tenant])
            ->postJson("/api/v1/support-grants/{$grantId}/approve")->assertOk()->assertJsonPath('state', 'approved');
        $this->assertSame('2026-10-24T10:00:30.000000Z', SupportAccessGrant::query()->findOrFail($grantId)->expires_at->toJSON());

        $grants = app(SupportGrants::class);
        $this->assertNotNull($grants->active($staff, $tenant, $store, SupportAccessGrant::READ_DIAGNOSTICS));
        $this->assertNull($grants->active($staff, $other['tenant']->id, $other['store']->id, SupportAccessGrant::READ_DIAGNOSTICS));
        $this->assertNull($grants->active($staff, $tenant, $store, SupportAccessGrant::READ_ARTIFACTS));
        $grants->require($staff, $tenant, $store, SupportAccessGrant::READ_DIAGNOSTICS, 'store', $store);
        Carbon::setTestNow(Carbon::now()->addHours(25));
        $this->assertNull($grants->active($staff, $tenant, $store, SupportAccessGrant::READ_DIAGNOSTICS));
        Carbon::setTestNow(Carbon::now()->subHours(25));
        $this->getJson("/api/v1/support-grants/{$grantId}/activity")->assertOk()->assertJsonFragment(['action' => 'support_access.opened']);
        $this->assertDatabaseHas('platform_audit_log', ['actor_staff_id' => $staff->id, 'action' => 'support_access.opened', 'target_tenant_id' => $tenant]);

        $this->postJson("/api/v1/support-grants/{$grantId}/revoke")->assertOk()->assertJsonPath('state', 'revoked');
        $this->assertNull($grants->active($staff, $tenant, $store, SupportAccessGrant::READ_DIAGNOSTICS));
        $this->postJson("/api/v1/support-grants/{$grantId}/revoke")->assertOk()->assertJsonPath('state', 'revoked');
    }

    public function test_customer_can_request_by_staff_email_but_only_the_owner_can_approve_and_duration_is_bounded(): void
    {
        $customer = $this->context();
        $member = $this->context('admin', $customer['tenant'], $customer['store'], $customer['integration']);
        $staff = $this->signInStaff();
        $tenant = $customer['tenant']->id;
        $input = [
            'store_id' => $customer['store']->id, 'staff_email' => $staff->user->email,
            'scopes' => ['read_diagnostics', 'read_artifacts'], 'duration_hours' => 72,
            'reason' => 'Please inspect case 17',
        ];

        $this->actingAs($member['user'])->withSession(['active_tenant_id' => $tenant])->postJson('/api/v1/support-grants', $input)->assertForbidden();
        $this->actingAs($customer['user'])->withSession(['active_tenant_id' => $tenant]);
        $this->postJson('/api/v1/support-grants', [...$input, 'case_reference' => 'MANUAL-17'])->assertStatus(422)->assertJsonValidationErrors('case_reference');
        $this->postJson('/api/v1/support-grants', [...$input, 'duration_hours' => 73])->assertStatus(422);
        $grantId = $this->postJson('/api/v1/support-grants', $input)->assertCreated()->assertJsonPath('state', 'pending')->assertJsonPath('case_reference', 'SUP-000001')->json('id');
        $this->actingAs($member['user'])->withSession(['active_tenant_id' => $tenant])->postJson("/api/v1/support-grants/{$grantId}/approve")->assertForbidden();
        $this->actingAs($customer['user'])->withSession(['active_tenant_id' => $tenant])->postJson("/api/v1/support-grants/{$grantId}/approve")->assertOk();
        $this->assertDatabaseHas('support_access_grants', ['id' => $grantId, 'requested_by_user_id' => $customer['user']->id, 'approved_by' => $customer['user']->id]);
        $this->assertSame(1, DB::table('audit_log')->where('action', 'support_grant.approved')->where('entity_id', $grantId)->count());
    }

    private function signInStaff(): PlatformStaff
    {
        $user = User::query()->create(['name' => 'Support Agent', 'email' => 'support@platform.test', 'password_hash' => Hash::make('support-password-1234'), 'locale' => 'en']);
        $mfa = app(MfaService::class);
        $secret = $mfa->beginSetup($user)['secret'];
        $totp = new Totp;
        $code = $totp->codeAt($secret, $totp->step(Carbon::now()->getTimestamp()));
        $mfa->confirm($user, $code);
        Carbon::setTestNow(Carbon::now()->addSeconds(30));
        $code = $totp->codeAt($secret, $totp->step(Carbon::now()->getTimestamp()));
        $staff = PlatformStaff::query()->create(['user_id' => $user->id, 'role' => PlatformStaff::ROLE_SUPPORT, 'state' => PlatformStaff::STATE_ACTIVE]);
        $this->postJson('/platform-api/v1/auth/login', ['email' => $user->email, 'password' => 'support-password-1234'])->assertOk();
        $this->postJson('/platform-api/v1/auth/mfa', ['code' => $code])->assertOk();

        return $staff->load('user');
    }
}
