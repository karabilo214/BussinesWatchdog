<?php

namespace Tests\Feature\Account;

use App\Mail\NotificationMessageMail;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AccountApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_password_reset_answers_the_same_for_unknown_addresses_and_mails_known_ones(): void
    {
        $user = $this->user();

        $this->postJson('/api/v1/auth/password-reset/request', ['email' => 'nobody@example.test'])->assertStatus(202)->assertJsonPath('status', 'requested');
        Mail::assertNothingSent();

        $this->postJson('/api/v1/auth/password-reset/request', ['email' => ' OWNER@example.test '])->assertStatus(202)->assertJsonPath('status', 'requested');
        $link = $this->mailedLink($user->email);

        $this->assertStringContainsString('/app/reset-password?', $link);
        $this->assertNotNull(DB::table('password_reset_tokens')->where('email', $user->email)->value('token'));
        $this->assertStringNotContainsString($this->tokenFrom($link), (string) DB::table('password_reset_tokens')->value('token'));
    }

    public function test_password_reset_is_one_time_ends_sessions_and_confirms_the_address(): void
    {
        $user = $this->user(verified: false);
        DB::table('sessions')->insert(['id' => 'old-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        $this->postJson('/api/v1/auth/password-reset/request', ['email' => $user->email]);
        $token = $this->tokenFrom($this->mailedLink($user->email));

        $this->postJson('/api/v1/auth/password-reset/complete', ['email' => $user->email, 'token' => 'wrong', 'password' => 'a-brand-new-password', 'password_confirmation' => 'a-brand-new-password'])
            ->assertStatus(422)->assertJsonPath('code', 'password_reset_invalid');

        $this->postJson('/api/v1/auth/password-reset/complete', ['email' => $user->email, 'token' => $token, 'password' => 'a-brand-new-password', 'password_confirmation' => 'a-brand-new-password'])
            ->assertNoContent();

        $user->refresh();
        $this->assertTrue(Hash::check('a-brand-new-password', $user->password_hash));
        $this->assertNotNull($user->email_verified_at);
        $this->assertDatabaseMissing('sessions', ['id' => 'old-session']);

        $this->postJson('/api/v1/auth/password-reset/complete', ['email' => $user->email, 'token' => $token, 'password' => 'yet-another-password', 'password_confirmation' => 'yet-another-password'])
            ->assertStatus(422)->assertJsonPath('code', 'password_reset_invalid');
    }

    public function test_password_reset_link_expires_after_an_hour(): void
    {
        $user = $this->user();
        $this->postJson('/api/v1/auth/password-reset/request', ['email' => $user->email]);
        $token = $this->tokenFrom($this->mailedLink($user->email));
        $this->travel(61)->minutes();

        $this->postJson('/api/v1/auth/password-reset/complete', ['email' => $user->email, 'token' => $token, 'password' => 'a-brand-new-password', 'password_confirmation' => 'a-brand-new-password'])
            ->assertStatus(422)->assertJsonPath('code', 'password_reset_invalid');
    }

    public function test_registration_mails_a_signed_link_that_confirms_the_address_without_a_session(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'New Owner', 'email' => 'new@example.test', 'password' => 'very-secure-password', 'password_confirmation' => 'very-secure-password',
            'organization_name' => 'New GmbH', 'timezone' => 'Europe/Berlin', 'locale' => 'de',
        ])->assertCreated()->assertJsonPath('user.email_verified', false);
        $link = $this->mailedLink('new@example.test');
        $path = (string) parse_url($link, PHP_URL_PATH).'?'.parse_url($link, PHP_URL_QUERY);

        $this->post('/api/v1/auth/logout');
        $this->app['auth']->forgetGuards();

        $this->get(str_replace('signature=', 'signature=x', $path))->assertRedirect('/app/overview?email_verified=0');
        $this->assertNull(User::query()->where('email', 'new@example.test')->value('email_verified_at'));

        $this->get($path)->assertRedirect('/app/overview?email_verified=1');
        $this->assertNotNull(User::query()->where('email', 'new@example.test')->value('email_verified_at'));
    }

    public function test_verification_can_be_resent_once_a_minute_and_not_after_confirmation(): void
    {
        $user = $this->user(verified: false);

        $this->actingAs($user)->postJson('/api/v1/auth/email-verification/resend')->assertStatus(202);
        $this->actingAs($user)->postJson('/api/v1/auth/email-verification/resend')->assertStatus(429);

        $user->forceFill(['email_verified_at' => now()])->save();
        $this->travel(2)->minutes();
        $this->actingAs($user)->postJson('/api/v1/auth/email-verification/resend')->assertStatus(409)->assertJsonPath('code', 'email_already_verified');
    }

    public function test_profile_and_password_can_be_changed_and_other_sessions_end(): void
    {
        $user = $this->user();
        DB::table('sessions')->insert(['id' => 'other-device', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

        $this->actingAs($user)->patchJson('/api/v1/auth/me', ['name' => 'Renamed', 'locale' => 'en'])
            ->assertOk()->assertJsonPath('user.name', 'Renamed')->assertJsonPath('user.locale', 'en');

        $this->actingAs($user)->postJson('/api/v1/auth/password', ['current_password' => 'wrong-password-123', 'password' => 'a-brand-new-password', 'password_confirmation' => 'a-brand-new-password'])
            ->assertStatus(422)->assertJsonValidationErrors(['current_password']);

        $this->actingAs($user)->postJson('/api/v1/auth/password', ['current_password' => 'very-secure-password', 'password' => 'a-brand-new-password', 'password_confirmation' => 'a-brand-new-password'])
            ->assertNoContent();

        $this->assertTrue(Hash::check('a-brand-new-password', $user->refresh()->password_hash));
        $this->assertDatabaseMissing('sessions', ['id' => 'other-device']);
    }

    private function user(bool $verified = true): User
    {
        $user = User::query()->create(['name' => 'Owner', 'email' => 'owner@example.test', 'password_hash' => Hash::make('very-secure-password'), 'locale' => 'ru']);
        $user->forceFill(['email_verified_at' => $verified ? now() : null])->save();
        $tenant = Tenant::query()->create(['name' => 'Demo', 'timezone' => 'Europe/Berlin']);
        Membership::query()->create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => 'owner']);

        return $user;
    }

    private function mailedLink(string $email): string
    {
        $link = null;

        Mail::assertSent(NotificationMessageMail::class, function (NotificationMessageMail $mail) use ($email, &$link): bool {
            if ($mail->hasTo($email) && preg_match('#https?://\S+#', $mail->bodyText, $matches)) {
                $link = $matches[0];

                return true;
            }

            return false;
        });

        return (string) $link;
    }

    private function tokenFrom(string $link): string
    {
        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);

        return (string) ($query['token'] ?? '');
    }
}
