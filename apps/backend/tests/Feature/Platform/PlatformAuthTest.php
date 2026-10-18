<?php

namespace Tests\Feature\Platform;

use App\Models\Membership;
use App\Models\PlatformStaff;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Account\MfaService;
use App\Support\Account\Totp;
use App\Support\Platform\PlatformCapabilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PlatformAuthTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = '';

    public function test_owner_signs_in_with_password_and_authenticator_code_in_a_separate_session(): void
    {
        $staff = $this->staff();

        $this->getJson('/platform-api/v1/me')->assertStatus(401)->assertJsonPath('code', 'platform_unauthenticated');
        $this->postJson('/platform-api/v1/auth/login', ['email' => 'owner@example.test', 'password' => 'wrong-password-0000'])
            ->assertStatus(422)->assertJsonPath('code', 'platform_login_failed');

        $this->postJson('/platform-api/v1/auth/login', ['email' => 'Owner@Example.test', 'password' => 'owner-password-1234'])
            ->assertOk()->assertJsonPath('mfa_required', true);
        $this->getJson('/platform-api/v1/me')->assertStatus(401);
        $this->postJson('/platform-api/v1/auth/mfa', ['code' => '000000'])->assertStatus(422)->assertJsonPath('code', 'mfa_code_invalid');

        $this->postJson('/platform-api/v1/auth/mfa', ['code' => $this->code()])
            ->assertOk()
            ->assertJsonPath('role', 'platform_owner')
            ->assertJsonPath('mfa_enabled', true)
            ->assertJsonPath('capabilities', PlatformCapabilities::ALL);

        $this->getJson('/platform-api/v1/me')->assertOk()->assertJsonPath('staff_id', $staff->id);
        $this->assertSame(['staff.login:denied', 'staff.login:denied', 'staff.login:succeeded'], DB::table('platform_audit_log')->orderBy('id')->get()->map(fn ($row) => $row->action.':'.$row->result)->all());

        $this->postJson('/platform-api/v1/auth/logout')->assertNoContent();
        $this->getJson('/platform-api/v1/me')->assertStatus(401);
    }

    public function test_customer_session_and_customer_accounts_never_open_the_platform_api(): void
    {
        $this->staff();
        $customer = User::query()->create(['name' => 'Customer', 'email' => 'customer@example.test', 'password_hash' => Hash::make('customer-password-1234'), 'locale' => 'ru']);
        $tenant = Tenant::query()->create(['name' => 'Shop GmbH', 'timezone' => 'Europe/Berlin']);
        Membership::query()->create(['tenant_id' => $tenant->id, 'user_id' => $customer->id, 'role' => 'owner']);

        $this->actingAs($customer)->getJson('/platform-api/v1/me')->assertStatus(401);
        $owner = User::query()->where('email', 'owner@example.test')->firstOrFail();
        $this->actingAs($owner, 'web')->getJson('/platform-api/v1/me')->assertStatus(401);

        $this->postJson('/platform-api/v1/auth/login', ['email' => 'customer@example.test', 'password' => 'customer-password-1234'])
            ->assertStatus(422)->assertJsonPath('code', 'platform_login_failed');
    }

    public function test_the_control_panel_uses_its_own_session_and_csrf_cookies(): void
    {
        $response = $this->get('/platform-api/v1/csrf-cookie')->assertNoContent();
        $names = array_map(fn ($cookie) => $cookie->getName(), $response->headers->getCookies());

        $this->assertContains('BW-PLATFORM-XSRF', $names);
        $this->assertContains('bw-platform-session', $names);
        $this->assertNotContains('XSRF-TOKEN', $names);
        $this->assertNotContains(config('session.cookie'), $names);
        $this->assertNotSame('bw-platform-session', config('session.cookie'));
    }

    public function test_staff_without_authenticator_or_disabled_cannot_sign_in(): void
    {
        $staff = $this->staff(false);

        $this->postJson('/platform-api/v1/auth/login', ['email' => 'owner@example.test', 'password' => 'owner-password-1234'])
            ->assertStatus(403)->assertJsonPath('code', 'platform_mfa_enrollment_required');

        $staff->forceFill(['state' => PlatformStaff::STATE_DISABLED])->save();
        $this->postJson('/platform-api/v1/auth/login', ['email' => 'owner@example.test', 'password' => 'owner-password-1234'])
            ->assertStatus(422)->assertJsonPath('code', 'platform_login_failed');
    }

    public function test_the_session_ends_after_30_idle_minutes_8_hours_or_a_permission_change(): void
    {
        Carbon::setTestNow('2026-10-12T08:00:00Z');
        $staff = $this->signedIn();

        Carbon::setTestNow('2026-10-12T08:29:00Z');
        $this->getJson('/platform-api/v1/me')->assertOk();
        Carbon::setTestNow('2026-10-12T09:00:00Z');
        $this->getJson('/platform-api/v1/me')->assertStatus(401)->assertJsonPath('code', 'platform_session_expired');

        Carbon::setTestNow('2026-10-12T10:00:00Z');
        $this->signedIn($staff);

        foreach (range(1, 16) as $half) {
            Carbon::setTestNow(Carbon::parse('2026-10-12T10:00:00Z')->addMinutes(29 * $half));
            $this->getJson('/platform-api/v1/me')->assertStatus($half * 29 <= 480 ? 200 : 401);
        }

        Carbon::setTestNow('2026-10-13T08:00:00Z');
        $this->signedIn($staff);
        $staff->forceFill(['permission_version' => 2])->save();
        $this->getJson('/platform-api/v1/me')->assertStatus(401);
    }

    public function test_the_login_is_rate_limited(): void
    {
        $this->staff();

        foreach (range(1, 5) as $attempt) {
            $this->postJson('/platform-api/v1/auth/login', ['email' => 'owner@example.test', 'password' => 'wrong-password-'.$attempt])->assertStatus(422);
        }

        $this->postJson('/platform-api/v1/auth/login', ['email' => 'owner@example.test', 'password' => 'owner-password-1234'])->assertStatus(429);
    }

    public function test_the_bootstrap_command_creates_an_owner_and_sets_up_the_authenticator(): void
    {
        $this->secret = (new Totp)->generateSecret();
        $this->app->instance(Totp::class, new class($this->secret) extends Totp
        {
            public function __construct(private readonly string $fixed) {}

            public function generateSecret(): string
            {
                return $this->fixed;
            }
        });
        $this->artisan('platform:create-owner', ['email' => 'Founder@Example.test', '--name' => 'Founder'])
            ->expectsQuestion('Password (at least 12 characters)', 'founder-password-1234')
            ->expectsQuestion('Repeat the password', 'founder-password-1234')
            ->expectsQuestion('Code from the app', '000000')
            ->expectsOutput('The code is not valid.')
            ->expectsQuestion('Code from the app', $this->code())
            ->expectsOutputToContain('Two-factor authentication is on.')
            ->assertSuccessful();

        $user = User::query()->where('email', 'founder@example.test')->firstOrFail();
        $this->assertTrue(app(MfaService::class)->enabled($user));
        $this->assertSame(PlatformStaff::ROLE_OWNER, PlatformStaff::query()->where('user_id', $user->id)->value('role'));
        $this->assertSame('staff.bootstrap_owner', DB::table('platform_audit_log')->value('action'));
        $this->assertSame('system', DB::table('platform_audit_log')->value('actor_kind'));
    }

    private function staff(bool $mfa = true): PlatformStaff
    {
        $user = User::query()->create(['name' => 'Owner', 'email' => 'owner@example.test', 'password_hash' => Hash::make('owner-password-1234'), 'locale' => 'ru']);

        if ($mfa) {
            $this->secret = app(MfaService::class)->beginSetup($user)['secret'];
            app(MfaService::class)->confirm($user, $this->code());
            Carbon::setTestNow(Carbon::now()->addSeconds(30));
        }

        return PlatformStaff::query()->create(['user_id' => $user->id, 'role' => PlatformStaff::ROLE_OWNER, 'state' => PlatformStaff::STATE_ACTIVE]);
    }

    private function signedIn(?PlatformStaff $staff = null): PlatformStaff
    {
        $staff ??= $this->staff();
        $this->postJson('/platform-api/v1/auth/login', ['email' => 'owner@example.test', 'password' => 'owner-password-1234'])->assertOk();
        $this->postJson('/platform-api/v1/auth/mfa', ['code' => $this->code()])->assertOk();

        return $staff;
    }

    private function code(): string
    {
        $totp = new Totp;

        return $totp->codeAt($this->secret, $totp->step(Carbon::now()->getTimestamp()));
    }
}
