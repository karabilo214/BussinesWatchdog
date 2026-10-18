<?php

namespace Tests\Feature\Platform;

use App\Mail\NotificationMessageMail;
use App\Models\PlatformStaff;
use App\Models\User;
use App\Support\Account\MfaService;
use App\Support\Account\Totp;
use App\Support\Platform\PlatformCapabilities;
use App\Support\Platform\StaffAdmin;
use App\Support\Platform\StaffRejected;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class StaffAdminTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string> */
    private array $secrets = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20T10:00:00Z');
        config()->set('watchdog.site.owner_url', 'https://control.watchdog.test/owner');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_owner_invites_a_staff_member_who_sets_a_password_and_an_authenticator_and_can_sign_in(): void
    {
        Mail::fake();
        $owner = $this->staff('owner@platform.test', PlatformStaff::ROLE_OWNER);
        $this->signIn('owner@platform.test');

        $this->postJson('/platform-api/v1/staff/invitations', ['email' => 'ops@platform.test', 'role' => 'platform_member', 'capabilities' => ['service.read'], 'reason' => 'New ops', 'confirmation_code' => '000000'])->assertForbidden()->assertJsonPath('code', 'step_up_failed');
        $this->postJson('/platform-api/v1/staff/invitations', ['email' => 'ops@platform.test', 'role' => 'platform_root', 'reason' => 'New ops', 'confirmation_code' => $this->code('owner@platform.test')])->assertStatus(422);
        foreach ([[], ['staff.manage'], ['refunds.approve'], ['service.read', 'root.everything']] as $invalid) {
            $this->postJson('/platform-api/v1/staff/invitations', ['email' => 'ops@platform.test', 'role' => 'platform_member', 'capabilities' => $invalid, 'reason' => 'New ops', 'confirmation_code' => $this->code('owner@platform.test')])->assertStatus(422)->assertJsonPath('code', 'capabilities_invalid');
        }
        $this->postJson('/platform-api/v1/staff/invitations', ['email' => 'owner@platform.test', 'role' => 'platform_member', 'capabilities' => ['service.read'], 'reason' => 'Twice', 'confirmation_code' => $this->code('owner@platform.test')])->assertStatus(409)->assertJsonPath('code', 'already_staff');
        $invitation = $this->postJson('/platform-api/v1/staff/invitations', ['email' => ' Ops@Platform.test ', 'role' => 'platform_member', 'capabilities' => ['service.read', 'plans.manage', 'tenants.read'], 'reason' => 'New ops', 'confirmation_code' => $this->code('owner@platform.test')])
            ->assertCreated()->assertJsonPath('email', 'ops@platform.test')->assertJsonPath('capabilities', ['tenants.read', 'plans.read', 'plans.manage', 'service.read'])->json();
        $this->assertSame('2026-10-23', substr($invitation['expires_at'], 0, 10));

        $token = null;
        Mail::assertSent(NotificationMessageMail::class, function (NotificationMessageMail $mail) use (&$token): bool {
            preg_match('/staff-invitation\?token=([A-Za-z0-9]+)/', $mail->bodyText, $match);
            $token = $match[1] ?? null;

            return $mail->hasTo('ops@platform.test') && str_contains($mail->bodyText, 'https://control.watchdog.test/owner/staff-invitation?token=') && str_contains($mail->bodyText, 'сотрудник');
        });
        $this->assertCount(1, $this->getJson('/platform-api/v1/staff')->assertOk()->json('invitations'));
        $this->postJson('/platform-api/v1/auth/logout')->assertNoContent();

        $this->getJson('/platform-api/v1/staff-invitations/lookup?token=wrong')->assertStatus(422)->assertJsonPath('code', 'invitation_invalid');
        $this->getJson('/platform-api/v1/staff-invitations/lookup?token='.$token)->assertOk()->assertJsonPath('role', 'platform_member')->assertJsonPath('capabilities', ['tenants.read', 'plans.read', 'plans.manage', 'service.read'])->assertJsonPath('account_exists', false);
        $this->postJson('/platform-api/v1/staff-invitations/confirm', ['token' => $token, 'code' => '123456'])->assertStatus(409)->assertJsonPath('code', 'invitation_not_started');
        $this->postJson('/platform-api/v1/staff-invitations/start', ['token' => $token, 'name' => 'Ops', 'password' => 'short'])->assertStatus(422);
        $setup = $this->postJson('/platform-api/v1/staff-invitations/start', ['token' => $token, 'name' => 'Ops Person', 'password' => 'ops-password-1234'])->assertOk()->assertJsonPath('mfa_enabled', false)->json();
        $this->assertStringStartsWith('otpauth://', $setup['otpauth_uri']);
        $this->secrets['ops@platform.test'] = $setup['secret'];
        $this->assertSame(0, PlatformStaff::query()->where('role', 'platform_member')->count());
        $this->postJson('/platform-api/v1/staff-invitations/confirm', ['token' => $token, 'code' => '000000'])->assertStatus(422)->assertJsonPath('code', 'mfa_code_invalid');
        $joined = $this->postJson('/platform-api/v1/staff-invitations/confirm', ['token' => $token, 'code' => $this->code('ops@platform.test')])->assertOk()->json();
        $this->assertNotEmpty($joined['recovery_codes']);
        $this->getJson('/platform-api/v1/staff-invitations/lookup?token='.$token)->assertStatus(422);

        $this->signIn('ops@platform.test', 'ops-password-1234');
        $this->getJson('/platform-api/v1/me')->assertOk()->assertJsonPath('role', 'platform_member')->assertJsonPath('capabilities', ['tenants.read', 'plans.read', 'plans.manage', 'service.read']);
        $this->getJson('/platform-api/v1/health')->assertOk();
        $this->getJson('/platform-api/v1/staff')->assertForbidden();
        $this->getJson('/platform-api/v1/audit')->assertForbidden();
        $this->assertSame(1, DB::table('platform_audit_log')->where('action', 'staff.joined')->count());
        $this->assertSame('New ops', DB::table('platform_audit_log')->where('action', 'staff.invited')->where('result', 'succeeded')->value('reason'));
        $this->assertSame('ops@platform.test', json_decode((string) DB::table('platform_audit_log')->where('action', 'staff.invited')->where('result', 'succeeded')->value('sanitized_changes'), true)['email']);
        $this->assertSame($owner->id, DB::table('platform_staff_invitations')->value('invited_by'));
    }

    public function test_existing_account_with_an_authenticator_joins_with_its_password_and_code(): void
    {
        Mail::fake();
        $this->staff('owner@platform.test', PlatformStaff::ROLE_OWNER);
        $user = User::query()->create(['name' => 'Anna', 'email' => 'anna@shop.test', 'password_hash' => Hash::make('anna-password-1234'), 'locale' => 'de']);
        $this->secrets['anna@shop.test'] = app(MfaService::class)->beginSetup($user)['secret'];
        app(MfaService::class)->confirm($user, $this->code('anna@shop.test'));
        $this->signIn('owner@platform.test');
        $this->postJson('/platform-api/v1/staff/invitations', ['email' => 'anna@shop.test', 'role' => 'platform_member', 'capabilities' => ['contacts.manage'], 'reason' => 'Support', 'confirmation_code' => $this->code('owner@platform.test')])->assertCreated();
        preg_match('/token=([A-Za-z0-9]+)/', Mail::sent(NotificationMessageMail::class)->last()->bodyText, $match);
        $this->postJson('/platform-api/v1/auth/logout')->assertNoContent();

        $this->getJson('/platform-api/v1/staff-invitations/lookup?token='.$match[1])->assertJsonPath('account_exists', true);
        $this->postJson('/platform-api/v1/staff-invitations/start', ['token' => $match[1], 'password' => 'wrong-password-1234'])->assertStatus(422)->assertJsonPath('code', 'password_wrong');
        $this->postJson('/platform-api/v1/staff-invitations/start', ['token' => $match[1], 'password' => 'anna-password-1234'])->assertOk()->assertJsonPath('mfa_enabled', true)->assertJsonMissingPath('secret');
        $this->postJson('/platform-api/v1/staff-invitations/confirm', ['token' => $match[1], 'code' => $this->code('anna@shop.test')])->assertOk()->assertJsonPath('recovery_codes', []);
        $joined = PlatformStaff::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame('platform_member', $joined->role);
        $this->assertSame(['contacts.read', 'contacts.manage'], $joined->capabilities);
    }

    public function test_roles_and_access_change_with_guards_for_the_last_owner_and_oneself_and_sessions_are_revoked(): void
    {
        $owner = $this->staff('owner@platform.test', PlatformStaff::ROLE_OWNER);
        $second = $this->staff('second@platform.test', PlatformStaff::ROLE_OWNER);
        $support = $this->staff('support@platform.test', PlatformStaff::ROLE_SUPPORT);
        $this->signIn('owner@platform.test');

        $this->patchJson("/platform-api/v1/staff/{$owner->id}", ['role' => 'platform_member', 'capabilities' => ['service.read'], 'reason' => 'Self', 'confirmation_code' => $this->code('owner@platform.test')])->assertStatus(409)->assertJsonPath('code', 'self_change');
        $catalog = $this->patchJson("/platform-api/v1/staff/{$support->id}", ['capabilities' => ['refunds.request', 'plans.read'], 'reason' => 'Moved to finance', 'confirmation_code' => $this->code('owner@platform.test')])->assertOk()->json();
        $row = collect($catalog['staff'])->firstWhere('id', $support->id);
        $this->assertSame('platform_member', $row['role']);
        $this->assertSame(['billing.read', 'refunds.request', 'plans.read'], $row['capabilities']);
        $this->assertTrue($row['mfa_enabled']);
        $this->assertSame(2, $support->fresh()->permission_version);

        $this->patchJson("/platform-api/v1/staff/{$second->id}", ['state' => 'disabled', 'reason' => 'Left the company', 'confirmation_code' => $this->code('owner@platform.test')])->assertOk();
        $this->assertNotNull($second->fresh()->disabled_at);
        $this->assertSame('disabled', $second->fresh()->state);

        $this->postJson('/platform-api/v1/auth/logout')->assertNoContent();
        $this->signIn('support@platform.test');
        $this->postJson('/platform-api/v1/auth/logout')->assertNoContent();
        $this->signIn('owner@platform.test');
        $this->postJson("/platform-api/v1/staff/{$support->id}/sessions/revoke", ['reason' => 'Lost laptop'])->assertNoContent();
        $this->assertSame(3, $support->fresh()->permission_version);
        $this->postJson("/platform-api/v1/staff/{$owner->id}/sessions/revoke", ['reason' => 'Self'])->assertStatus(409);

        DB::table('platform_staff')->where('id', $owner->id)->update(['role' => 'platform_owner']);
        $this->patchJson("/platform-api/v1/staff/{$second->id}", ['state' => 'active', 'reason' => 'Back', 'confirmation_code' => $this->code('owner@platform.test')])->assertOk();
        $this->postJson('/platform-api/v1/auth/logout')->assertNoContent();
        $this->signIn('second@platform.test');
        $this->patchJson("/platform-api/v1/staff/{$owner->id}", ['state' => 'disabled', 'reason' => 'Test', 'confirmation_code' => $this->code('second@platform.test')])->assertOk();
        $this->patchJson("/platform-api/v1/staff/{$owner->id}", ['state' => 'active', 'reason' => 'Test', 'confirmation_code' => $this->code('second@platform.test')])->assertOk();
        DB::table('platform_staff')->where('id', $owner->id)->update(['role' => 'platform_member', 'capabilities' => json_encode(['service.read'])]);
        $this->postJson('/platform-api/v1/auth/logout')->assertNoContent();
        $this->signIn('second@platform.test');
        $this->patchJson("/platform-api/v1/staff/{$second->id}", ['role' => 'platform_member', 'capabilities' => ['service.read'], 'reason' => 'Self', 'confirmation_code' => $this->code('second@platform.test')])->assertStatus(409);

        $this->assertGreaterThanOrEqual(4, DB::table('platform_audit_log')->where('action', 'staff.updated')->where('result', 'succeeded')->count());
        $this->assertSame('Lost laptop', DB::table('platform_audit_log')->where('action', 'staff.sessions_revoked')->value('reason'));
    }

    public function test_a_member_can_do_exactly_what_the_owner_gave_and_a_change_ends_their_sessions(): void
    {
        $this->staff('owner@platform.test', PlatformStaff::ROLE_OWNER);
        $member = $this->staff('ops@platform.test', PlatformStaff::ROLE_SUPPORT);
        $this->assertSame('platform_member', $member->role);
        $this->assertSame(['tenants.read', 'customers.mfa_reset', 'tenants.support', 'contacts.read', 'contacts.manage'], $member->capabilities);

        $this->signIn('ops@platform.test');
        $this->getJson('/platform-api/v1/contact-requests')->assertOk();
        $this->getJson('/platform-api/v1/pages')->assertForbidden();
        $this->postJson('/platform-api/v1/auth/logout')->assertNoContent();

        $this->signIn('owner@platform.test');
        $catalog = $this->getJson('/platform-api/v1/staff')->assertOk()->json();
        $this->assertSame(PlatformCapabilities::ALL, array_column($catalog['capability_catalog'], 'code'));
        $this->assertSame(['refunds.approve', 'staff.manage'], array_values(array_column(array_filter($catalog['capability_catalog'], fn (array $item): bool => $item['owner_only']), 'code')));
        $this->assertArrayHasKey('platform_billing', $catalog['presets']);
        $this->patchJson("/platform-api/v1/staff/{$member->id}", ['capabilities' => ['content.manage'], 'reason' => 'Writes the site now', 'confirmation_code' => $this->code('owner@platform.test')])->assertOk();
        $this->patchJson("/platform-api/v1/staff/{$member->id}", ['capabilities' => [], 'reason' => 'Nothing', 'confirmation_code' => $this->code('owner@platform.test')])->assertStatus(422)->assertJsonPath('code', 'capabilities_invalid');
        $audit = json_decode((string) DB::table('platform_audit_log')->where('action', 'staff.updated')->where('result', 'succeeded')->value('sanitized_changes'), true);
        $this->assertSame(['content.manage'], $audit['after']['capabilities']);
        $this->assertSame(['tenants.read', 'customers.mfa_reset', 'tenants.support', 'contacts.read', 'contacts.manage'], $audit['before']['capabilities']);
        $this->postJson('/platform-api/v1/auth/logout')->assertNoContent();

        $this->signIn('ops@platform.test');
        $this->getJson('/platform-api/v1/pages')->assertOk();
        $this->getJson('/platform-api/v1/contact-requests')->assertForbidden();

        $owner = app(StaffAdmin::class)->update(PlatformStaff::query()->whereHas('user', fn ($query) => $query->where('email', 'owner@platform.test'))->firstOrFail(), $member->id, ['role' => 'platform_owner'], 'Promoted');
        $this->assertNull($owner->fresh()->capabilities);
        $this->assertSame(PlatformCapabilities::ALL, PlatformCapabilities::forStaff($owner->fresh()));
    }

    public function test_the_last_active_owner_cannot_be_demoted_or_disabled_even_by_service_calls(): void
    {
        $owner = $this->staff('owner@platform.test', PlatformStaff::ROLE_OWNER);
        $admin = $this->staff('admin@platform.test', PlatformStaff::ROLE_ADMIN);
        $staff = app(StaffAdmin::class);

        foreach ([['state' => 'disabled'], ['role' => 'platform_member', 'capabilities' => ['tenants.read']]] as $change) {
            try {
                $staff->update($admin, $owner->id, $change, 'Test');
                $this->fail('the last owner must stay');
            } catch (StaffRejected $exception) {
                $this->assertSame('last_owner', $exception->reasonCode);
            }
        }

        $this->assertSame('active', $owner->fresh()->state);
        $this->assertSame('platform_owner', $owner->fresh()->role);
        $this->staff('second@platform.test', PlatformStaff::ROLE_OWNER);
        $this->assertSame('disabled', $staff->update($admin, $owner->id, ['state' => 'disabled'], 'Test')->state);
    }

    public function test_the_owner_resets_a_member_authenticator_and_the_member_connects_a_new_one_by_link(): void
    {
        Mail::fake();
        $owner = $this->staff('owner@platform.test', PlatformStaff::ROLE_OWNER);
        $second = $this->staff('second@platform.test', PlatformStaff::ROLE_OWNER);
        $member = $this->staff('ops@platform.test', PlatformStaff::ROLE_SUPPORT);
        $this->signIn('owner@platform.test');

        $this->postJson("/platform-api/v1/staff/{$member->id}/mfa/reset", ['reason' => 'Lost phone', 'confirmation_code' => '000000'])->assertForbidden()->assertJsonPath('code', 'step_up_failed');
        $this->postJson("/platform-api/v1/staff/{$second->id}/mfa/reset", ['reason' => 'Owner', 'confirmation_code' => $this->code('owner@platform.test')])->assertStatus(409)->assertJsonPath('code', 'owner_mfa_server_only');
        $this->postJson("/platform-api/v1/staff/{$owner->id}/mfa/reset", ['reason' => 'Self', 'confirmation_code' => $this->code('owner@platform.test')])->assertStatus(409)->assertJsonPath('code', 'self_change');
        $catalog = $this->postJson("/platform-api/v1/staff/{$member->id}/mfa/reset", ['reason' => 'Lost phone', 'confirmation_code' => $this->code('owner@platform.test')])->assertOk()->json();
        $this->assertFalse(collect($catalog['staff'])->firstWhere('id', $member->id)['mfa_enabled']);
        $this->assertSame([], $catalog['invitations'], 'A reset link is not a pending invitation.');
        $this->assertSame(2, $member->fresh()->permission_version);
        $this->assertSame('Lost phone', DB::table('platform_audit_log')->where('action', 'staff.mfa_reset')->where('result', 'succeeded')->value('reason'));

        $token = null;
        Mail::assertSent(NotificationMessageMail::class, function (NotificationMessageMail $mail) use (&$token): bool {
            preg_match('/staff-invitation\?token=([A-Za-z0-9]+)/', $mail->bodyText, $match);
            $token = $match[1] ?? null;

            return $mail->hasTo('ops@platform.test') && str_contains($mail->subjectLine, 'новый аутентификатор');
        });
        $this->postJson('/platform-api/v1/auth/logout')->assertNoContent();

        $this->postJson('/platform-api/v1/auth/login', ['email' => 'ops@platform.test', 'password' => 'staff-password-1234'])->assertStatus(403);
        $this->getJson('/platform-api/v1/staff-invitations/lookup?token='.$token)->assertOk()->assertJsonPath('purpose', 'mfa_reset')->assertJsonPath('account_exists', true);
        $this->postJson('/platform-api/v1/staff-invitations/start', ['token' => $token, 'password' => 'wrong-password-1234'])->assertStatus(422)->assertJsonPath('code', 'password_wrong');
        $setup = $this->postJson('/platform-api/v1/staff-invitations/start', ['token' => $token, 'password' => 'staff-password-1234'])->assertOk()->assertJsonPath('mfa_enabled', false)->json();
        $this->secrets['ops@platform.test'] = $setup['secret'];
        $this->postJson('/platform-api/v1/staff-invitations/confirm', ['token' => $token, 'code' => $this->code('ops@platform.test')])->assertOk()->assertJsonPath('staff_id', $member->id);
        $this->assertSame(3, PlatformStaff::query()->count());
        $this->signIn('ops@platform.test');
        $this->getJson('/platform-api/v1/me')->assertOk()->assertJsonPath('staff_id', $member->id);
        $this->assertSame(1, DB::table('platform_audit_log')->where('action', 'staff.mfa_reenrolled')->count());
        $this->getJson('/platform-api/v1/staff-invitations/lookup?token='.$token)->assertStatus(422);
    }

    public function test_reset_mfa_command_disables_the_old_authenticator_ends_sessions_and_is_audited(): void
    {
        $member = $this->staff('ops@platform.test', PlatformStaff::ROLE_ADMIN);

        $this->artisan('platform:reset-mfa', ['email' => 'nobody@platform.test'])->assertFailed();
        $this->artisan('platform:reset-mfa', ['email' => 'ops@platform.test', '--reason' => 'Lost phone'])
            ->expectsConfirmation('Reset the authenticator of ops@platform.test and end their sessions?', 'yes')
            ->expectsQuestion('Code from the app', '000000')
            ->expectsQuestion('Code from the app', '000000')
            ->expectsQuestion('Code from the app', '000000')
            ->assertFailed();

        $this->assertFalse(app(MfaService::class)->enabled($member->user->fresh()));
        $this->assertSame(2, $member->fresh()->permission_version);
        $this->assertSame('Lost phone', DB::table('platform_audit_log')->where('action', 'staff.mfa_reset')->where('target_id', $member->id)->value('reason'));
    }

    private function staff(string $email, string $role): PlatformStaff
    {
        $user = User::query()->create(['name' => explode('@', $email)[0], 'email' => $email, 'password_hash' => Hash::make('staff-password-1234'), 'locale' => 'ru']);
        $mfa = app(MfaService::class);
        $this->secrets[$email] = $mfa->beginSetup($user)['secret'];
        $mfa->confirm($user, $this->code($email));

        return PlatformStaff::query()->create(['user_id' => $user->id, 'role' => $role, 'state' => PlatformStaff::STATE_ACTIVE]);
    }

    private function signIn(string $email, string $password = 'staff-password-1234'): void
    {
        $this->postJson('/platform-api/v1/auth/login', ['email' => $email, 'password' => $password])->assertOk();
        $this->postJson('/platform-api/v1/auth/mfa', ['code' => $this->code($email)])->assertOk();
    }

    private function code(string $email): string
    {
        Carbon::setTestNow(Carbon::now()->addSeconds(30));
        $totp = new Totp;

        return $totp->codeAt($this->secrets[$email], $totp->step(Carbon::now()->getTimestamp()));
    }
}
