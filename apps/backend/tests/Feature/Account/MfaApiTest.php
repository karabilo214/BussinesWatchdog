<?php

namespace Tests\Feature\Account;

use App\Models\AuditLog;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Account\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MfaApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_codes_match_the_rfc_6238_reference_values(): void
    {
        $totp = new Totp;
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

        $this->assertSame('287082', $totp->codeAt($secret, $totp->step(59)));
        $this->assertSame('081804', $totp->codeAt($secret, $totp->step(1111111109)));
        $this->assertSame(1, $totp->verify($secret, '287082', 59, null));
        $this->assertNull($totp->verify($secret, '287082', 59, 1), 'a used step must not be accepted again');
        $this->assertNull($totp->verify($secret, '287082', 59 + 90, null), 'outside the drift window');
    }

    public function test_enrollment_needs_the_password_and_a_confirming_code_and_ends_other_sessions(): void
    {
        $user = $this->user();
        DB::table('sessions')->insert(['id' => 'other-device', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

        $this->actingAs($user)->postJson('/api/v1/auth/mfa/setup', ['current_password' => 'wrong-password-123'])
            ->assertStatus(422)->assertJsonValidationErrors(['current_password']);
        $setup = $this->actingAs($user)->postJson('/api/v1/auth/mfa/setup', ['current_password' => 'very-secure-password'])->assertOk();
        $secret = $setup->json('secret');

        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
        $this->assertStringStartsWith('otpauth://totp/Business%20Watchdog:owner%40example.test?secret='.$secret, $setup->json('otpauth_uri'));
        $this->assertStringContainsString('<svg', $setup->json('qr_svg'));
        $this->assertStringNotContainsString($secret, (string) $user->refresh()->mfa_secret_ciphertext);
        $this->actingAs($user)->getJson('/api/v1/auth/mfa')->assertExactJson(['enabled' => false, 'recovery_codes_remaining' => 0]);
        $this->actingAs($user)->getJson('/api/v1/auth/me')->assertJsonPath('user.mfa_enabled', false);

        $this->actingAs($user)->postJson('/api/v1/auth/mfa/confirm', ['code' => '000000'])->assertStatus(422)->assertJsonPath('code', 'mfa_code_invalid');
        $confirmed = $this->actingAs($user)->postJson('/api/v1/auth/mfa/confirm', ['code' => $this->code($secret)])->assertOk();

        $this->assertTrue($confirmed->json('enabled'));
        $this->assertCount(10, $confirmed->json('recovery_codes'));
        $this->assertMatchesRegularExpression('/^[a-z2-9]{5}-[a-z2-9]{5}$/', $confirmed->json('recovery_codes.0'));
        $this->assertDatabaseMissing('sessions', ['id' => 'other-device']);
        $this->assertDatabaseMissing('mfa_recovery_codes', ['code_hash' => $confirmed->json('recovery_codes.0')]);
        $this->assertSame(1, AuditLog::query()->where('action', 'user.mfa_enabled')->where('entity_id', $user->id)->count());
        $this->actingAs($user)->postJson('/api/v1/auth/mfa/setup', ['current_password' => 'very-secure-password'])->assertStatus(409)->assertJsonPath('code', 'mfa_already_enabled');
    }

    public function test_sign_in_with_mfa_needs_a_second_step_and_every_code_works_once(): void
    {
        [$user, $secret, $recovery] = $this->enrolled();

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'very-secure-password'])->assertOk()->assertExactJson(['mfa_required' => true]);
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->postJson('/api/v1/auth/mfa/challenge', ['code' => $this->code($secret)])->assertStatus(422)->assertJsonPath('code', 'mfa_code_invalid');

        $this->travel(31)->seconds();
        $this->postJson('/api/v1/auth/mfa/challenge', ['code' => $this->code($secret)])->assertOk()->assertJsonPath('user.mfa_enabled', true);
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('user.id', $user->id);

        $this->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'very-secure-password'])->assertExactJson(['mfa_required' => true]);
        $this->postJson('/api/v1/auth/mfa/challenge', ['code' => strtoupper($recovery[0])])->assertOk();
        $this->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'very-secure-password']);
        $this->postJson('/api/v1/auth/mfa/challenge', ['code' => $recovery[0]])->assertStatus(422);
        $this->assertSame(9, $this->actingAs($user)->getJson('/api/v1/auth/mfa')->json('recovery_codes_remaining'));
    }

    public function test_the_second_step_expires_after_five_failures_or_five_minutes(): void
    {
        [$user, $secret] = $this->enrolled();
        $this->travel(31)->seconds();

        $this->postJson('/api/v1/auth/mfa/challenge', ['code' => $this->code($secret)])->assertStatus(401)->assertJsonPath('code', 'mfa_challenge_expired');

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'very-secure-password']);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/mfa/challenge', ['code' => '000000'])->assertStatus(422);
        }
        $this->postJson('/api/v1/auth/mfa/challenge', ['code' => $this->code($secret)])->assertStatus(401)->assertJsonPath('code', 'mfa_challenge_expired');

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'very-secure-password']);
        $this->travel(6)->minutes();
        $this->postJson('/api/v1/auth/mfa/challenge', ['code' => $this->code($secret)])->assertStatus(401);
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_disabling_and_new_recovery_codes_need_the_password_and_a_code(): void
    {
        [$user, $secret] = $this->enrolled();
        $this->travel(31)->seconds();

        $this->actingAs($user)->postJson('/api/v1/auth/mfa/recovery-codes', ['current_password' => 'very-secure-password'])
            ->assertStatus(422)->assertJsonPath('errors.code.0', 'mfa_code_required');
        $codes = $this->actingAs($user)->postJson('/api/v1/auth/mfa/recovery-codes', ['current_password' => 'very-secure-password', 'code' => $this->code($secret)])->assertOk();
        $this->assertCount(10, $codes->json('recovery_codes'));

        $this->actingAs($user)->postJson('/api/v1/auth/mfa/disable', ['current_password' => 'very-secure-password', 'code' => '123456'])
            ->assertStatus(422)->assertJsonPath('errors.code.0', 'mfa_code_invalid');
        $this->actingAs($user)->postJson('/api/v1/auth/mfa/disable', ['current_password' => 'very-secure-password', 'code' => $codes->json('recovery_codes.3')])
            ->assertOk()->assertExactJson(['enabled' => false, 'recovery_codes_remaining' => 0]);
        $this->assertDatabaseCount('mfa_recovery_codes', 0);
        $this->assertNull($user->refresh()->mfa_secret_ciphertext);
        $this->postJson('/api/v1/auth/logout');
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'very-secure-password'])->assertJsonPath('user.id', $user->id);
    }

    public function test_the_owner_hands_the_team_to_a_confirmed_member_and_stays_as_admin(): void
    {
        $owner = $this->user();
        $tenantId = Membership::query()->where('user_id', $owner->id)->value('tenant_id');
        $admin = $this->member($tenantId, 'admin@example.test', 'admin');
        $unverified = $this->member($tenantId, 'new@example.test', 'viewer', verified: false);
        $session = ['active_tenant_id' => $tenantId];

        $this->actingAs($admin)->withSession($session)->postJson('/api/v1/ownership-transfer', ['target_user_id' => $owner->id, 'current_password' => 'very-secure-password'])
            ->assertStatus(403);
        $this->actingAs($owner)->withSession($session)->postJson('/api/v1/ownership-transfer', ['target_user_id' => $admin->id, 'current_password' => 'wrong-password-123'])
            ->assertStatus(422)->assertJsonValidationErrors(['current_password']);
        $this->actingAs($owner)->withSession($session)->postJson('/api/v1/ownership-transfer', ['target_user_id' => $unverified->id, 'current_password' => 'very-secure-password'])
            ->assertStatus(422)->assertJsonPath('code', 'target_email_unverified');
        $this->actingAs($owner)->withSession($session)->postJson('/api/v1/ownership-transfer', ['target_user_id' => $owner->id, 'current_password' => 'very-secure-password'])
            ->assertStatus(422)->assertJsonPath('code', 'cannot_transfer_to_self');

        $this->actingAs($owner)->withSession($session)->postJson('/api/v1/ownership-transfer', ['target_user_id' => $admin->id, 'current_password' => 'very-secure-password'])
            ->assertNoContent();

        $roles = Membership::query()->where('tenant_id', $tenantId)->pluck('role', 'user_id')->all();
        $counts = array_count_values($roles);
        ksort($counts);
        $this->assertSame(['admin' => 1, 'owner' => 1, 'viewer' => 1], $counts);
        $this->assertSame('owner', $roles[$admin->id]);
        $this->assertSame('admin', $roles[$owner->id]);
        $audit = AuditLog::query()->where('action', 'membership.ownership_transferred')->firstOrFail();
        $this->assertSame([$owner->id, $admin->id], [$audit->changes['owner']['from'], $audit->changes['owner']['to']]);
        $this->actingAs($owner)->withSession($session)->postJson('/api/v1/ownership-transfer', ['target_user_id' => $admin->id, 'current_password' => 'very-secure-password'])
            ->assertStatus(403);
    }

    public function test_an_owner_with_mfa_must_give_a_code_to_transfer_ownership(): void
    {
        [$owner, $secret] = $this->enrolled();
        $tenantId = Membership::query()->where('user_id', $owner->id)->value('tenant_id');
        $admin = $this->member($tenantId, 'admin@example.test', 'admin');
        $this->travel(31)->seconds();

        $this->actingAs($owner)->withSession(['active_tenant_id' => $tenantId])->postJson('/api/v1/ownership-transfer', ['target_user_id' => $admin->id, 'current_password' => 'very-secure-password'])
            ->assertStatus(422)->assertJsonPath('errors.code.0', 'mfa_code_required');
        $this->assertSame('owner', Membership::query()->where('user_id', $owner->id)->value('role'));
        $this->actingAs($owner)->withSession(['active_tenant_id' => $tenantId])->postJson('/api/v1/ownership-transfer', ['target_user_id' => $admin->id, 'current_password' => 'very-secure-password', 'code' => $this->code($secret)])
            ->assertNoContent();
        $this->assertSame('owner', Membership::query()->where('user_id', $admin->id)->value('role'));
    }

    /**
     * @return array{0: User, 1: string, 2: list<string>}
     */
    private function enrolled(): array
    {
        $user = $this->user();
        $secret = $this->actingAs($user)->postJson('/api/v1/auth/mfa/setup', ['current_password' => 'very-secure-password'])->json('secret');
        $codes = $this->actingAs($user)->postJson('/api/v1/auth/mfa/confirm', ['code' => $this->code($secret)])->json('recovery_codes');
        $this->postJson('/api/v1/auth/logout');
        $this->app['auth']->forgetGuards();

        return [$user->refresh(), $secret, $codes];
    }

    private function code(string $secret): string
    {
        $totp = new Totp;

        return $totp->codeAt($secret, $totp->step(now()->getTimestamp()));
    }

    private function user(): User
    {
        $user = User::query()->create(['name' => 'Owner', 'email' => 'owner@example.test', 'password_hash' => Hash::make('very-secure-password'), 'locale' => 'ru']);
        $user->forceFill(['email_verified_at' => now()])->save();
        $tenant = Tenant::query()->create(['name' => 'Demo', 'timezone' => 'Europe/Berlin']);
        Membership::query()->create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => 'owner']);

        return $user;
    }

    private function member(string $tenantId, string $email, string $role, bool $verified = true): User
    {
        $user = User::query()->create(['name' => $role, 'email' => $email, 'password_hash' => Hash::make('very-secure-password'), 'locale' => 'ru']);
        $user->forceFill(['email_verified_at' => $verified ? now() : null])->save();
        Membership::query()->create(['tenant_id' => $tenantId, 'user_id' => $user->id, 'role' => $role]);

        return $user;
    }
}
