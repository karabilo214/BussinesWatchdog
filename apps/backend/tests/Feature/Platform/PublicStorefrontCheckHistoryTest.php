<?php

namespace Tests\Feature\Platform;

use App\Models\PlatformStaff;
use App\Models\PublicStorefrontCheck;
use App\Models\User;
use App\Support\Account\MfaService;
use App\Support\Account\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PublicStorefrontCheckHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_history_is_staff_only_and_keeps_metadata_after_public_result_expires(): void
    {
        Carbon::setTestNow('2026-10-20T10:00:00Z');

        $check = PublicStorefrontCheck::query()->create([
            'result_token_hash' => hash('sha256', 'history-token'),
            'locale' => 'en',
            'submitted_url' => 'https://shop.example.com/product',
            'normalized_origin' => 'https://shop.example.com',
            'normalized_host' => 'shop.example.com',
            'ip_hash' => hash('sha256', '203.0.113.44'),
            'request_ip' => '203.0.113.44',
            'request_user_agent' => 'TestBrowser/1.0',
            'status' => PublicStorefrontCheck::STATUS_PASSED,
            'verdict' => PublicStorefrontCheck::VERDICT_CHECKOUT_REACHED,
            'browser_version' => 'Chromium 143',
            'queued_at' => now(),
            'finished_at' => now(),
            'expires_at' => now()->addMinutes(5),
        ]);

        $this->getJson('/platform-api/v1/public-storefront-checks')->assertUnauthorized();
        $this->signIn(PlatformStaff::ROLE_OWNER);
        $this->getJson('/platform-api/v1/public-storefront-checks')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.0.url', 'https://shop.example.com')
            ->assertJsonPath('data.0.ip', '203.0.113.44')
            ->assertJsonPath('data.0.user_agent', 'TestBrowser/1.0')
            ->assertJsonPath('data.0.worker_browser', 'Chromium 143')
            ->assertJsonPath('data.0.status', 'passed');

        Carbon::setTestNow('2026-10-20T10:06:00Z');
        $this->artisan('site:public-checks-purge')->assertExitCode(0);
        $this->assertNotNull($check->fresh()?->purged_at);
        $this->getJson('/platform-api/v1/public-storefront-checks')->assertJsonPath('data.0.ip', '203.0.113.44');

        Carbon::setTestNow('2027-01-19T10:00:00Z');
        $this->artisan('site:public-checks-purge')->assertExitCode(0);
        $this->assertDatabaseMissing('public_storefront_checks', ['id' => $check->id]);
        Carbon::setTestNow();
    }

    public function test_billing_staff_cannot_read_visitor_ip_history(): void
    {
        Carbon::setTestNow('2026-10-20T10:00:00Z');
        $this->signIn(PlatformStaff::ROLE_BILLING);
        $this->getJson('/platform-api/v1/public-storefront-checks')->assertForbidden();
        Carbon::setTestNow();
    }

    private function signIn(string $role): void
    {
        $user = User::query()->create(['name' => 'Staff', 'email' => 'history@platform.test', 'password_hash' => Hash::make('staff-password-1234'), 'locale' => 'ru']);
        $mfa = app(MfaService::class);
        $secret = $mfa->beginSetup($user)['secret'];
        Carbon::setTestNow(Carbon::now()->addSeconds(30));
        $totp = new Totp;
        $mfa->confirm($user, $totp->codeAt($secret, $totp->step(Carbon::now()->getTimestamp())));
        PlatformStaff::query()->create(['user_id' => $user->id, 'role' => $role, 'state' => PlatformStaff::STATE_ACTIVE]);
        $this->postJson('/platform-api/v1/auth/login', ['email' => 'history@platform.test', 'password' => 'staff-password-1234'])->assertOk();
        Carbon::setTestNow(Carbon::now()->addSeconds(30));
        $this->postJson('/platform-api/v1/auth/mfa', ['code' => $totp->codeAt($secret, $totp->step(Carbon::now()->getTimestamp()))])->assertOk();
    }
}
