<?php

namespace Tests\Feature\Account;

use App\Mail\NotificationMessageMail;
use App\Models\EmailChangeRequest;
use App\Models\Membership;
use App\Models\NotificationChannel;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Account\MfaService;
use App\Support\Account\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class EmailChangeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->user = User::query()->create(['name' => 'Owner', 'email' => 'owner@example.test', 'password_hash' => Hash::make('very-secure-password'), 'locale' => 'en']);
        $this->user->forceFill(['email_verified_at' => now()])->save();
        $this->tenant = Tenant::query()->create(['name' => 'Demo', 'timezone' => 'Europe/Berlin']);
        Membership::query()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id, 'role' => 'owner']);
    }

    public function test_a_change_needs_the_password_and_with_mfa_a_code(): void
    {
        $this->change('new@example.test', 'wrong-password-123')->assertStatus(422)->assertJsonValidationErrors(['current_password']);
        $this->change('Owner@Example.test')->assertStatus(422)->assertJsonPath('code', 'email_unchanged');
        $this->change('not-an-address')->assertStatus(422)->assertJsonValidationErrors(['email']);

        $mfa = app(MfaService::class);
        $secret = $mfa->beginSetup($this->user)['secret'];
        $totp = new Totp;
        $mfa->confirm($this->user, $totp->codeAt($secret, $totp->step(now()->getTimestamp())));
        $this->change('new@example.test')->assertStatus(422)->assertJsonValidationErrors(['code']);

        $this->travel(1)->minutes();
        $this->change('new@example.test', code: $totp->codeAt($secret, $totp->step(now()->getTimestamp())))->assertStatus(202);
        Mail::assertSent(NotificationMessageMail::class, fn (NotificationMessageMail $mail): bool => $mail->hasTo('new@example.test'));
    }

    public function test_the_address_changes_only_after_the_new_one_confirms_and_then_every_session_ends(): void
    {
        $channel = NotificationChannel::query()->create(['tenant_id' => $this->tenant->id, 'kind' => NotificationChannel::KIND_EMAIL, 'destination_ciphertext' => Crypt::encryptString('owner@example.test'), 'key_version' => 1, 'label' => 'Me', 'enabled' => true, 'verified_at' => now(), 'preferences' => [], 'health' => [], 'created_at' => now(), 'updated_at' => now()]);
        DB::table('sessions')->insert(['id' => 'other-device', 'user_id' => $this->user->id, 'payload' => '', 'last_activity' => time()]);

        $this->change(' New@Example.test ')->assertStatus(202)->assertJsonPath('email', 'new@example.test');
        $warning = $this->mail('owner@example.test');
        $this->assertStringContainsString('n***@example.test', $warning->bodyText);
        $this->assertStringNotContainsString('new@example.test', $warning->bodyText);
        $this->assertStringContainsString('/cancel/', $warning->bodyText);
        $confirm = $this->link($this->mail('new@example.test'));

        $this->as()->getJson('/api/v1/auth/me')->assertJsonPath('user.email', 'owner@example.test')->assertJsonPath('user.pending_email_change.email', 'new@example.test');
        $this->assertSame('owner@example.test', $this->user->refresh()->email);
        $this->app['auth']->forgetGuards();

        $this->get($confirm)->assertRedirect('/app/login?email_changed=1');

        $user = $this->user->refresh();
        $this->assertSame('new@example.test', $user->email);
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertStringContainsString('n***@example.test', collect(Mail::sent(NotificationMessageMail::class))->last(fn (NotificationMessageMail $mail): bool => $mail->hasTo('owner@example.test'))->bodyText);
        $changes = json_decode((string) DB::table('audit_log')->where('action', 'user.email_changed')->value('changes'), true);
        $this->assertEquals(['before' => 'o***@example.test', 'after' => 'n***@example.test', 'sessions_revoked' => true], $changes);
        $this->assertSame('owner@example.test', Crypt::decryptString($channel->refresh()->destination_ciphertext));

        $this->get($confirm)->assertRedirect('/app/login?email_changed=0');
        $this->postJson('/api/v1/auth/login', ['email' => 'new@example.test', 'password' => 'very-secure-password'])->assertOk();
    }

    public function test_a_new_request_replaces_the_previous_link_and_links_expire_or_need_the_exact_token(): void
    {
        $this->change('first@example.test')->assertStatus(202);
        $first = $this->link($this->mail('first@example.test'));
        $this->change('second@example.test')->assertStatus(202);
        $second = $this->link($this->mail('second@example.test'));

        $this->get($first)->assertRedirect('/app/login?email_changed=0');
        $this->get(preg_replace('#/confirm/[^/]+$#', '/confirm/'.Str::random(48), $second))->assertRedirect('/app/login?email_changed=0');
        $this->travel(25)->hours();
        $this->get($second)->assertRedirect('/app/login?email_changed=0');
        $this->assertSame('owner@example.test', $this->user->refresh()->email);
    }

    public function test_the_old_address_can_stop_the_change_and_end_every_session_and_trusted_device(): void
    {
        DB::table('sessions')->insert(['id' => 'other-device', 'user_id' => $this->user->id, 'payload' => '', 'last_activity' => time()]);
        DB::table('trusted_devices')->insert(['id' => (string) Str::uuid(), 'user_id' => $this->user->id, 'token_hash' => str_repeat('c', 64), 'device' => 'Chrome', 'expires_at' => now()->addDays(30), 'created_at' => now(), 'updated_at' => now()]);
        $this->change('new@example.test')->assertStatus(202);
        $cancel = $this->link($this->mail('owner@example.test'));
        $confirm = $this->link($this->mail('new@example.test'));
        $this->app['auth']->forgetGuards();

        $this->get($cancel)->assertRedirect('/app/login?email_change_cancelled=1');
        $this->get($confirm)->assertRedirect('/app/login?email_changed=0');

        $this->assertSame('owner@example.test', $this->user->refresh()->email);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $this->user->id)->count());
        $this->assertSame(0, DB::table('trusted_devices')->where('user_id', $this->user->id)->count());
        $this->assertDatabaseHas('audit_log', ['action' => 'user.email_change_cancelled', 'tenant_id' => $this->tenant->id]);
    }

    public function test_an_address_of_another_account_is_never_revealed_on_screen(): void
    {
        User::query()->create(['name' => 'Other', 'email' => 'taken@example.test', 'password_hash' => Hash::make('other-secure-password'), 'locale' => 'de']);

        $this->change('TAKEN@example.test')->assertStatus(202)->assertJsonPath('email', 'taken@example.test');
        $letter = $this->mail('taken@example.test');
        $this->assertStringContainsString('/app/forgot-password', $letter->bodyText);
        $this->assertStringNotContainsString('/confirm/', $letter->bodyText);
        $this->assertNull(EmailChangeRequest::query()->value('confirm_token_hash'));
        $this->as()->getJson('/api/v1/auth/me')->assertJsonPath('user.pending_email_change.email', 'taken@example.test');
        $this->as()->postJson('/api/v1/auth/email-change/resend')->assertStatus(202);
        $this->assertSame('owner@example.test', $this->user->refresh()->email);
    }

    public function test_a_pending_change_can_be_resent_or_cancelled_and_requests_are_limited(): void
    {
        $this->as()->postJson('/api/v1/auth/email-change/cancel')->assertNotFound()->assertJsonPath('code', 'email_change_not_pending');
        $this->change('new@example.test')->assertStatus(202);
        $this->as()->postJson('/api/v1/auth/email-change/resend')->assertStatus(202);
        $this->assertCount(2, collect(Mail::sent(NotificationMessageMail::class))->filter(fn (NotificationMessageMail $mail): bool => $mail->hasTo('new@example.test')));
        $this->as()->postJson('/api/v1/auth/email-change/cancel')->assertNoContent();
        $this->as()->getJson('/api/v1/auth/me')->assertJsonPath('user.pending_email_change', null);

        foreach (range(2, 4) as $attempt) {
            $this->change("try{$attempt}@example.test")->assertStatus(202);
        }

        $this->change('try5@example.test')->assertStatus(429);
    }

    private function change(string $email, string $password = 'very-secure-password', ?string $code = null): TestResponse
    {
        return $this->as()->postJson('/api/v1/auth/email-change', ['email' => $email, 'current_password' => $password, 'code' => $code]);
    }

    private function as(): static
    {
        return $this->actingAs($this->user)->withSession(['active_tenant_id' => $this->tenant->id]);
    }

    private function mail(string $to): NotificationMessageMail
    {
        $mail = collect(Mail::sent(NotificationMessageMail::class))->last(fn (NotificationMessageMail $mail): bool => $mail->hasTo($to));
        $this->assertNotNull($mail, "no mail to {$to}");

        return $mail;
    }

    private function link(NotificationMessageMail $mail): string
    {
        preg_match('#https?://\S+/api/v1/auth/email-change/\S+#', $mail->bodyText, $matches);
        $this->assertNotEmpty($matches);

        return (string) parse_url($matches[0], PHP_URL_PATH);
    }
}
