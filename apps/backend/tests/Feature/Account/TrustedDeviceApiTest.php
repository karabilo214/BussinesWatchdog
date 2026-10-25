<?php

namespace Tests\Feature\Account;

use App\Models\AuditLog;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Account\Totp;
use App\Support\Account\TrustedDevices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TrustedDeviceApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_opt_in_cookie_skips_only_login_challenge_until_expiry_or_revocation(): void
    {
        [$user, $recovery] = $this->enrolled();
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'very-secure-password'])->assertExactJson(['mfa_required' => true]);
        $completed = $this->postJson('/api/v1/auth/mfa/challenge', ['code' => $recovery, 'remember_device' => true])->assertOk();
        $cookie = collect($completed->headers->getCookies())->first(fn ($item): bool => $item->getName() === TrustedDevices::COOKIE);
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('strict', $cookie->getSameSite());
        $this->assertSame('/api/v1/auth', $cookie->getPath());
        $this->assertDatabaseCount('trusted_devices', 1);
        $row = DB::table('trusted_devices')->first();
        $this->assertNotSame($cookie->getValue(), $row->token_hash);
        $this->assertSame(1, AuditLog::query()->where('actor_user_id', $user->id)->where('action', 'user.trusted_device_added')->count());

        $this->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->app['auth']->forgetGuards();
        $this->withCredentials()->withUnencryptedCookies([TrustedDevices::COOKIE => $cookie->getValue()])
            ->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'very-secure-password'])
            ->assertOk()->assertJsonPath('user.id', $user->id);

        $this->actingAs($user)->getJson('/api/v1/auth/trusted-devices')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.current', true)
            ->assertJsonMissing(['token_hash' => $row->token_hash]);
        $this->deleteJson('/api/v1/auth/trusted-devices/'.$row->id)->assertNoContent();
        $this->assertDatabaseCount('trusted_devices', 0);
        $this->assertSame(1, AuditLog::query()->where('actor_user_id', $user->id)->where('action', 'user.trusted_device_login')->count());
        $this->assertSame(1, AuditLog::query()->where('actor_user_id', $user->id)->where('action', 'user.trusted_device_revoked')->count());
        $this->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'very-secure-password'])
            ->assertExactJson(['mfa_required' => true]);
    }

    public function test_expired_trust_does_not_skip_mfa(): void
    {
        [$user, $recovery] = $this->enrolled();
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'very-secure-password']);
        $completed = $this->postJson('/api/v1/auth/mfa/challenge', ['code' => $recovery, 'remember_device' => true])->assertOk();
        $cookie = collect($completed->headers->getCookies())->first(fn ($item): bool => $item->getName() === TrustedDevices::COOKIE);

        $this->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->app['auth']->forgetGuards();
        $this->travel(31)->days();
        $this->withCredentials()->withUnencryptedCookies([TrustedDevices::COOKIE => $cookie->getValue()])
            ->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'very-secure-password'])
            ->assertExactJson(['mfa_required' => true]);
    }

    public function test_trust_is_not_issued_by_default_and_other_users_cannot_revoke_it(): void
    {
        [$user, $recovery] = $this->enrolled();
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'very-secure-password']);
        $this->postJson('/api/v1/auth/mfa/challenge', ['code' => $recovery])->assertOk();
        $this->assertDatabaseCount('trusted_devices', 0);

        app(TrustedDevices::class)->issue(request(), $user);
        $id = DB::table('trusted_devices')->value('id');
        $other = $this->user('other@example.test');
        $this->actingAs($other)->getJson('/api/v1/auth/trusted-devices')->assertOk()->assertJsonCount(0, 'data');
        $this->deleteJson('/api/v1/auth/trusted-devices/'.$id)->assertNotFound();
        $this->deleteJson('/api/v1/auth/trusted-devices/not-a-uuid')->assertNotFound();
        $this->assertDatabaseHas('trusted_devices', ['id' => $id]);
    }

    public function test_password_change_and_sign_out_everywhere_remove_trust(): void
    {
        [$user, $recovery] = $this->enrolled();
        $this->actingAs($user);
        app(TrustedDevices::class)->issue(request(), $user);
        $this->postJson('/api/v1/auth/password', [
            'current_password' => 'very-secure-password',
            'password' => 'another-secure-password',
            'password_confirmation' => 'another-secure-password',
        ])->assertNoContent();
        $this->assertDatabaseCount('trusted_devices', 0);

        app(TrustedDevices::class)->issue(request(), $user);
        $this->postJson('/api/v1/auth/sessions/revoke-all', ['current_password' => 'another-secure-password'])
            ->assertStatus(422); // MFA step-up remains required even for a trusted device.
        $this->assertDatabaseCount('trusted_devices', 1);
        $this->postJson('/api/v1/auth/sessions/revoke-all', ['current_password' => 'another-secure-password', 'code' => $recovery])->assertNoContent();
        $this->assertDatabaseCount('trusted_devices', 0);
    }

    public function test_password_reset_revokes_trust(): void
    {
        $user = $this->user();
        app(TrustedDevices::class)->issue(request(), $user);
        $this->assertDatabaseCount('trusted_devices', 1);
        DB::table('password_reset_tokens')->insert(['email' => $user->email, 'token' => hash('sha256', 'one-time-token'), 'created_at' => now()]);

        $this->postJson('/api/v1/auth/password-reset/complete', [
            'email' => $user->email,
            'token' => 'one-time-token',
            'password' => 'another-secure-password',
            'password_confirmation' => 'another-secure-password',
        ])->assertNoContent();
        $this->assertDatabaseCount('trusted_devices', 0);
    }

    /** @return array{User, string} */
    private function enrolled(): array
    {
        $user = $this->user();
        $secret = $this->actingAs($user)->postJson('/api/v1/auth/mfa/setup', ['current_password' => 'very-secure-password'])->json('secret');
        $codes = $this->postJson('/api/v1/auth/mfa/confirm', ['code' => app(Totp::class)->codeAt($secret, app(Totp::class)->step(now()->getTimestamp()))])->json('recovery_codes');
        $this->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->app['auth']->forgetGuards();

        return [$user->refresh(), $codes[0]];
    }

    private function user(string $email = 'owner@example.test'): User
    {
        $user = User::query()->create(['name' => 'Owner', 'email' => $email, 'password_hash' => Hash::make('very-secure-password'), 'locale' => 'ru']);
        $user->forceFill(['email_verified_at' => now()])->save();
        $tenant = Tenant::query()->create(['name' => 'Demo', 'timezone' => 'Europe/Berlin']);
        Membership::query()->create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => 'owner']);

        return $user;
    }
}
