<?php

namespace Tests\Feature\Platform;

use App\Mail\NotificationMessageMail;
use App\Models\PlatformStaff;
use App\Models\User;
use App\Support\Account\MfaService;
use App\Support\Account\Totp;
use App\Support\Platform\PlatformCapabilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StaffProfileTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string> */
    private array $secrets = [];

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake((string) config('watchdog.site.media_disk'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_owner_edits_anyone_and_a_member_only_themselves(): void
    {
        $owner = $this->staff('owner@example.test', PlatformStaff::ROLE_OWNER);
        $secondOwner = $this->staff('owner2@example.test', PlatformStaff::ROLE_OWNER);
        $member = $this->staff('member@example.test', PlatformStaff::ROLE_MEMBER, [PlatformCapabilities::TENANTS_READ]);
        $colleague = $this->staff('colleague@example.test', PlatformStaff::ROLE_MEMBER, [PlatformCapabilities::TENANTS_READ]);

        $this->signIn($member);
        $this->patchJson("/platform-api/v1/staff/{$member->id}/profile", ['first_name' => 'Mia', 'last_name' => 'Muster'])->assertOk()->assertJsonPath('name', 'Mia Muster')->assertJsonPath('can_edit', true);
        foreach ([$colleague, $owner] as $other) {
            $this->patchJson("/platform-api/v1/staff/{$other->id}/profile", ['last_name' => 'Hacked'])->assertForbidden()->assertJsonPath('code', 'staff_profile_forbidden');
            $this->postJson("/platform-api/v1/staff/{$other->id}/photo", ['photo' => $this->photo()])->assertForbidden();
            $this->deleteJson("/platform-api/v1/staff/{$other->id}/photo")->assertForbidden();
            $this->postJson("/platform-api/v1/staff/{$other->id}/password-link", ['confirmation_code' => $this->code($member)])->assertForbidden();
        }
        $this->assertSame('Staff', $colleague->user->refresh()->last_name);
        $this->assertDatabaseHas('platform_audit_log', ['action' => 'staff.profile_updated', 'target_id' => $owner->id, 'result' => 'denied']);

        $this->signOut();
        $this->signIn($owner);
        foreach ([$member, $secondOwner, $owner] as $other) {
            $this->patchJson("/platform-api/v1/staff/{$other->id}/profile", ['first_name' => '', 'last_name' => 'Renamed'])->assertOk()->assertJsonPath('first_name', null);
        }
        $changes = json_decode((string) DB::table('platform_audit_log')->where('action', 'staff.profile_updated')->where('target_id', $member->id)->where('result', 'succeeded')->latest('created_at')->value('sanitized_changes'), true);
        $this->assertEquals(['before' => ['first_name' => 'Mia', 'last_name' => 'Muster'], 'after' => ['first_name' => null, 'last_name' => 'Renamed']], $changes);
    }

    public function test_a_photo_is_cleaned_cut_to_a_square_shown_to_colleagues_and_can_be_removed(): void
    {
        $owner = $this->staff('owner@example.test', PlatformStaff::ROLE_OWNER);
        $member = $this->staff('member@example.test', PlatformStaff::ROLE_MEMBER, [PlatformCapabilities::TENANTS_READ]);
        $this->signIn($member);

        $this->postJson("/platform-api/v1/staff/{$member->id}/photo", ['photo' => UploadedFile::fake()->createWithContent('photo.png', '%PDF-1.4 not an image %%EOF')])->assertStatus(422)->assertJsonPath('code', 'media_type_unsupported');
        $url = $this->postJson("/platform-api/v1/staff/{$member->id}/photo", ['photo' => $this->photo(800, 400)])->assertOk()->json('photo_url');

        $stored = Storage::disk((string) config('watchdog.site.media_disk'))->get((string) $member->refresh()->photo_key);
        $this->assertSame([512, 512, IMAGETYPE_JPEG], array_slice(getimagesizefromstring($stored), 0, 3));
        $this->assertStringNotContainsString('Exif', $stored);
        $this->assertStringStartsWith('/platform-api/v1/staff/'.$member->id.'/photo?v=', $url);
        $this->assertDatabaseHas('platform_audit_log', ['action' => 'staff.photo_updated', 'target_id' => $member->id]);

        $this->signOut();
        $this->signIn($owner);
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->getJson('/platform-api/v1/staff')->assertOk()->assertJsonPath('staff.1.photo_url', $url);
        $this->getJson('/platform-api/v1/audit?action=staff.photo_updated')->assertOk()->assertJsonPath('data.0.actor.photo_url', $url);

        $this->deleteJson("/platform-api/v1/staff/{$member->id}/photo")->assertOk()->assertJsonPath('photo_url', null);
        $this->get($url)->assertNotFound();
        $this->assertSame([], Storage::disk((string) config('watchdog.site.media_disk'))->allFiles('staff-photos'));
    }

    public function test_own_password_needs_the_current_one_and_a_code_and_ends_other_sessions_only(): void
    {
        $member = $this->staff('member@example.test', PlatformStaff::ROLE_MEMBER, [PlatformCapabilities::TENANTS_READ]);
        $this->signIn($member);
        DB::table('sessions')->insert(['id' => 'other-device', 'user_id' => $member->user_id, 'payload' => '', 'last_activity' => time()]);

        $body = ['current_password' => 'wrong-password-1234', 'password' => 'a-brand-new-password', 'password_confirmation' => 'a-brand-new-password', 'confirmation_code' => $this->code($member)];
        $this->postJson('/platform-api/v1/me/password', $body)->assertStatus(422)->assertJsonPath('code', 'current_password_invalid');
        $this->postJson('/platform-api/v1/me/password', ['current_password' => 'staff-password-1234', 'confirmation_code' => '000000'] + $body)->assertForbidden()->assertJsonPath('code', 'step_up_failed');
        $this->postJson('/platform-api/v1/me/password', ['current_password' => 'staff-password-1234', 'password' => 'short', 'password_confirmation' => 'short', 'confirmation_code' => $this->code($member)])->assertStatus(422)->assertJsonValidationErrors(['password']);

        Carbon::setTestNow(now()->addSeconds(30));
        $this->postJson('/platform-api/v1/me/password', ['current_password' => 'staff-password-1234', 'confirmation_code' => $this->code($member)] + $body)->assertNoContent();

        $this->assertTrue(Hash::check('a-brand-new-password', $member->user->refresh()->password_hash));
        $this->assertDatabaseMissing('sessions', ['id' => 'other-device']);
        $this->getJson('/platform-api/v1/me')->assertOk();
        $this->assertDatabaseHas('platform_audit_log', ['action' => 'staff.password_changed', 'result' => 'succeeded']);
    }

    public function test_another_persons_password_is_set_only_by_them_through_a_one_time_link(): void
    {
        $owner = $this->staff('owner@example.test', PlatformStaff::ROLE_OWNER);
        $member = $this->staff('member@example.test', PlatformStaff::ROLE_MEMBER, [PlatformCapabilities::TENANTS_READ]);
        $versionBefore = $member->refresh()->permission_version;
        $this->signIn($owner);

        $this->postJson("/platform-api/v1/staff/{$member->id}/password-link", ['confirmation_code' => '000000'])->assertForbidden()->assertJsonPath('code', 'step_up_failed');
        $this->postJson("/platform-api/v1/staff/{$owner->id}/password-link", ['confirmation_code' => $this->code($owner)])->assertStatus(422)->assertJsonPath('code', 'use_own_password_change');
        Carbon::setTestNow(now()->addSeconds(30));
        $this->postJson("/platform-api/v1/staff/{$member->id}/password-link", ['confirmation_code' => $this->code($owner)])->assertNoContent();

        $link = '';
        Mail::assertSent(NotificationMessageMail::class, function (NotificationMessageMail $mail) use (&$link): bool {
            if ($mail->hasTo('member@example.test') && preg_match('#https?://\S+/owner/reset-password\?\S+#', $mail->bodyText, $matches)) {
                $link = $matches[0];

                return true;
            }

            return false;
        });
        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);
        $this->assertSame(Hash::check('staff-password-1234', $member->user->refresh()->password_hash), true);

        $this->signOut();
        $this->postJson('/platform-api/v1/password-reset/complete', ['email' => $query['email'], 'token' => 'wrong', 'password' => 'my-own-new-password', 'password_confirmation' => 'my-own-new-password'])->assertStatus(422)->assertJsonPath('code', 'password_reset_invalid');
        $this->postJson('/platform-api/v1/password-reset/complete', ['email' => $query['email'], 'token' => $query['token'], 'password' => 'my-own-new-password', 'password_confirmation' => 'my-own-new-password'])->assertNoContent();

        $this->assertTrue(Hash::check('my-own-new-password', $member->user->refresh()->password_hash));
        $this->assertSame($versionBefore + 1, $member->refresh()->permission_version);
        $this->assertDatabaseHas('platform_audit_log', ['action' => 'staff.password_link_sent', 'target_id' => $member->id, 'result' => 'succeeded']);
    }

    /**
     * @param  list<string>  $capabilities
     */
    private function staff(string $email, string $role, array $capabilities = []): PlatformStaff
    {
        $user = User::query()->create(['name' => 'Staff', 'email' => $email, 'password_hash' => Hash::make('staff-password-1234'), 'locale' => 'en']);
        $mfa = app(MfaService::class);
        $secret = $mfa->beginSetup($user)['secret'];
        $totp = new Totp;
        $mfa->confirm($user, $totp->codeAt($secret, $totp->step(now()->getTimestamp())));
        Carbon::setTestNow(now()->addSeconds(30));
        $this->secrets[$email] = $secret;

        return PlatformStaff::query()->create(['user_id' => $user->id, 'role' => $role, 'capabilities' => $capabilities, 'state' => PlatformStaff::STATE_ACTIVE])->load('user');
    }

    private function code(PlatformStaff $staff): string
    {
        $totp = new Totp;

        return $totp->codeAt($this->secrets[$staff->user->email], $totp->step(now()->getTimestamp()));
    }

    private function signIn(PlatformStaff $staff): void
    {
        Carbon::setTestNow(now()->addSeconds(30));
        $this->postJson('/platform-api/v1/auth/login', ['email' => $staff->user->email, 'password' => 'staff-password-1234'])->assertOk();
        $this->postJson('/platform-api/v1/auth/mfa', ['code' => $this->code($staff)])->assertOk();
        Carbon::setTestNow(now()->addSeconds(30));
    }

    private function signOut(): void
    {
        $this->postJson('/platform-api/v1/auth/logout')->assertNoContent();
        $this->app['auth']->forgetGuards();
    }

    private function photo(int $width = 300, int $height = 300): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 30, 120, 200));
        ob_start();
        imagejpeg($image);

        return UploadedFile::fake()->createWithContent('selfie.jpg', (string) ob_get_clean());
    }
}
