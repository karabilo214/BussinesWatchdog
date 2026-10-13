<?php

namespace Tests\Feature\Platform;

use App\Models\PlatformStaff;
use App\Models\User;
use App\Support\Account\MfaService;
use App\Support\Account\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Notifications\NotificationTestContext;
use Tests\TestCase;

class PlatformSettingsTest extends TestCase
{
    use NotificationTestContext;
    use RefreshDatabase;

    private string $secret = '';

    private const MESSAGES = ['ru' => 'Плановые работы, проверки приостановлены.', 'en' => 'Planned maintenance, checks paused.', 'de' => 'Geplante Wartung, Prüfungen pausiert.'];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20T10:00:00Z');
        config()->set('watchdog.billing.paddle.api_key', 'pdl_live_secret_key_value');
        config()->set('watchdog.billing.paddle.webhook_secret', null);
        config()->set('services.telegram.bot_token', '');
        config()->set('watchdog.site.contact_notify', 'a@x.test, b@x.test');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_settings_show_configuration_without_secrets_and_a_versioned_maintenance_window_reaches_customers(): void
    {
        $context = $this->context();
        $this->signIn();

        $response = $this->getJson('/platform-api/v1/settings')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $settings = $response->json();
        $this->assertTrue($settings['configuration']['billing']['api_key']['configured']);
        $this->assertStringStartsWith('sha256:', $settings['configuration']['billing']['api_key']['fingerprint']);
        $this->assertFalse($settings['configuration']['billing']['webhook_secret']['configured']);
        $this->assertFalse($settings['configuration']['telegram']['token']['configured']);
        $this->assertSame(2, $settings['configuration']['site']['contact_notify']);
        $this->assertStringNotContainsString('pdl_live_secret_key_value', (string) $response->getContent());
        $this->assertNull($settings['maintenance']['current']);

        $window = ['starts_at' => '2026-10-21T22:00:00Z', 'ends_at' => '2026-10-22T02:00:00Z', 'messages' => self::MESSAGES, 'reason' => 'Database upgrade'];
        $this->postJson('/platform-api/v1/settings/maintenance', $window + ['confirmation_code' => '000000'])->assertForbidden();
        $this->postJson('/platform-api/v1/settings/maintenance', array_merge($window, ['ends_at' => '2026-10-23T02:00:00Z', 'confirmation_code' => $this->code()]))->assertStatus(422)->assertJsonPath('code', 'maintenance_window_invalid');
        $this->postJson('/platform-api/v1/settings/maintenance', array_merge($window, ['ends_at' => '2026-10-21T21:00:00Z', 'confirmation_code' => $this->code()]))->assertStatus(422)->assertJsonPath('code', 'maintenance_window_invalid');
        $this->postJson('/platform-api/v1/settings/maintenance', array_merge($window, ['messages' => ['ru' => 'ok ok', 'en' => 'ok ok'], 'confirmation_code' => $this->code()]))->assertStatus(422);
        $this->postJson('/platform-api/v1/settings/maintenance', $window + ['confirmation_code' => $this->code()])->assertCreated()
            ->assertJsonPath('maintenance.current.version', 1)->assertJsonPath('maintenance.current.created_by', 'Staff');

        $context['user']->forceFill(['locale' => 'de'])->save();
        $this->actingAs($context['user'])->withSession(['active_tenant_id' => $context['tenant']->id])->getJson('/api/v1/maintenance')->assertOk()
            ->assertJsonPath('notice.message', 'Geplante Wartung, Prüfungen pausiert.')->assertJsonPath('notice.active', false);
        $this->login();

        $second = $this->postJson('/platform-api/v1/settings/maintenance', array_merge($window, ['starts_at' => '2026-10-20T09:00:00Z', 'ends_at' => '2026-10-20T12:00:00Z', 'confirmation_code' => $this->code()]))->assertCreated()->json('maintenance');
        $this->assertSame(2, $second['current']['version']);
        $this->assertNotNull($second['history'][1]['cancelled_at']);
        $this->actingAs($context['user'])->withSession(['active_tenant_id' => $context['tenant']->id])->getJson('/api/v1/maintenance')->assertJsonPath('notice.active', true);
        $this->login();

        $this->postJson('/platform-api/v1/settings/maintenance/cancel', ['reason' => 'Done early', 'confirmation_code' => $this->code()])->assertOk()->assertJsonPath('maintenance.current', null);
        $this->postJson('/platform-api/v1/settings/maintenance/cancel', ['reason' => 'Again', 'confirmation_code' => $this->code()])->assertStatus(409)->assertJsonPath('code', 'maintenance_not_scheduled');
        $this->actingAs($context['user'])->withSession(['active_tenant_id' => $context['tenant']->id])->getJson('/api/v1/maintenance')->assertJsonPath('notice', null);
        $this->assertSame(['settings.maintenance_cancelled', 'settings.maintenance_scheduled', 'settings.maintenance_scheduled'], DB::table('platform_audit_log')->where('action', 'like', 'settings.%')->where('result', 'succeeded')->orderByDesc('created_at')->orderByDesc('id')->pluck('action')->all());
    }

    public function test_only_the_owner_sees_and_changes_settings(): void
    {
        $this->signIn(PlatformStaff::ROLE_ADMIN);
        $this->getJson('/platform-api/v1/settings')->assertForbidden();
        $this->postJson('/platform-api/v1/settings/maintenance/cancel', ['reason' => 'x', 'confirmation_code' => '1'])->assertForbidden();
    }

    private function signIn(string $role = PlatformStaff::ROLE_OWNER): void
    {
        $user = User::query()->create(['name' => 'Staff', 'email' => 'staff@platform.test', 'password_hash' => Hash::make('staff-password-1234'), 'locale' => 'ru']);
        $mfa = app(MfaService::class);
        $this->secret = $mfa->beginSetup($user)['secret'];
        $mfa->confirm($user, $this->code());
        PlatformStaff::query()->create(['user_id' => $user->id, 'role' => $role, 'state' => PlatformStaff::STATE_ACTIVE]);
        $this->login();
    }

    private function login(): void
    {
        $this->postJson('/platform-api/v1/auth/login', ['email' => 'staff@platform.test', 'password' => 'staff-password-1234'])->assertOk();
        $this->postJson('/platform-api/v1/auth/mfa', ['code' => $this->code()])->assertOk();
    }

    private function code(): string
    {
        Carbon::setTestNow(Carbon::now()->addSeconds(30));
        $totp = new Totp;

        return $totp->codeAt($this->secret, $totp->step(Carbon::now()->getTimestamp()));
    }
}
