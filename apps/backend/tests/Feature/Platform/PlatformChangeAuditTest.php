<?php

namespace Tests\Feature\Platform;

use App\Models\PlatformStaff;
use App\Models\User;
use App\Support\Account\MfaService;
use App\Support\Account\Totp;
use App\Support\Platform\PlatformAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Notifications\NotificationTestContext;
use Tests\TestCase;

class PlatformChangeAuditTest extends TestCase
{
    use NotificationTestContext;
    use RefreshDatabase;

    private string $secret = '';

    protected function setUp(): void
    {
        parent::setUp();

        Route::prefix('/platform-api/v1')->middleware(['platform', 'platform.staff'])->group(function (): void {
            Route::post('/test-change', fn () => response()->json(['ok' => true]));
            Route::post('/test-change/audited', function () {
                app(PlatformAudit::class)->record(request()->attributes->get('platform_staff')->id, 'test.done', 'test', 'x', PlatformAudit::RESULT_SUCCEEDED);

                return response()->json(['ok' => true]);
            });
            Route::post('/tenants/{tenant}/test-change', fn () => response()->json(['ok' => true]));
            Route::post('/test-change/failing', fn () => response()->json(['code' => 'nope'], 422));
            Route::post('/test-change/preview', fn () => response()->json(['ok' => true]));
            Route::get('/test-change', fn () => response()->json(['ok' => true]));
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_every_successful_change_from_the_panel_is_audited_once_and_views_are_not(): void
    {
        $context = $this->context();
        $tenant = $context['tenant']->id;
        $this->signIn();
        $baseline = DB::table('platform_audit_log')->count();

        $this->postJson('/platform-api/v1/test-change')->assertOk();
        $entry = DB::table('platform_audit_log')->where('action', 'platform.change')->first();
        $this->assertSame('platform-api/v1/test-change', $entry->target_id);
        $this->assertEqualsCanonicalizing(['method' => 'POST', 'route' => 'platform-api/v1/test-change', 'status' => 200], json_decode((string) $entry->sanitized_changes, true));

        $this->postJson('/platform-api/v1/test-change/audited')->assertOk();
        $this->assertSame(0, DB::table('platform_audit_log')->where('action', 'platform.change')->where('target_id', 'platform-api/v1/test-change/audited')->count(), 'An action with its own entry gets no second one.');

        $this->postJson("/platform-api/v1/tenants/{$tenant}/test-change")->assertOk();
        $this->assertSame($tenant, DB::table('platform_audit_log')->where('target_id', 'platform-api/v1/tenants/{tenant}/test-change')->value('target_tenant_id'));
        $organisation = DB::table('audit_log')->where('tenant_id', $tenant)->where('action', 'platform.change')->first();
        $this->assertNotNull($organisation);
        $this->assertSame('platform', json_decode((string) $organisation->changes, true)['by']);

        $this->postJson('/platform-api/v1/test-change/failing')->assertStatus(422);
        $this->postJson('/platform-api/v1/test-change/preview')->assertOk();
        $this->getJson('/platform-api/v1/test-change')->assertOk();
        $this->assertSame($baseline + 3, DB::table('platform_audit_log')->count());
    }

    public function test_an_organisation_change_that_writes_its_own_organisation_entry_gets_no_generic_one(): void
    {
        $context = $this->context();
        $tenant = $context['tenant']->id;
        $this->signIn();

        $this->postJson("/platform-api/v1/tenants/{$tenant}/invitations", ['email' => 'jonas@kaffee.test', 'role' => 'operator', 'reason' => 'Asked by phone'])->assertCreated();

        $this->assertSame(0, DB::table('audit_log')->where('tenant_id', $tenant)->where('action', 'platform.change')->count());
        $this->assertSame(0, DB::table('platform_audit_log')->where('action', 'platform.change')->count());
    }

    private function signIn(): void
    {
        $user = User::query()->create(['name' => 'Staff', 'email' => 'staff@platform.test', 'password_hash' => Hash::make('staff-password-1234'), 'locale' => 'ru']);
        $mfa = app(MfaService::class);
        $this->secret = $mfa->beginSetup($user)['secret'];
        $mfa->confirm($user, $this->code());
        PlatformStaff::query()->create(['user_id' => $user->id, 'role' => PlatformStaff::ROLE_OWNER, 'state' => PlatformStaff::STATE_ACTIVE]);
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
