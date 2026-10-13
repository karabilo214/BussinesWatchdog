<?php

namespace Tests\Feature\Platform;

use App\Models\PlatformStaff;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Account\MfaService;
use App\Support\Account\Totp;
use App\Support\Platform\PlatformAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuditJournalTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = '';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20T10:00:00Z');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_admin_filters_the_platform_audit_pages_through_it_and_exports_it(): void
    {
        $admin = $this->signIn(PlatformStaff::ROLE_ADMIN);
        $tenant = Tenant::query()->create(['name' => 'Kaffee GmbH', 'locale' => 'de', 'timezone' => 'Europe/Berlin']);
        $audit = app(PlatformAudit::class);
        Carbon::setTestNow('2026-10-18T09:00:00Z');
        $audit->record($admin->id, 'tenant.suspend', 'tenant', $tenant->id, PlatformAudit::RESULT_SUCCEEDED, ['access_before' => 'active'], 'Abuse report', $tenant->id);
        Carbon::setTestNow('2026-10-19T09:00:00Z');
        $audit->record($admin->id, 'tenant.suspend', 'tenant', $tenant->id, PlatformAudit::RESULT_DENIED, ['stage' => 'step_up'], null, $tenant->id);
        $audit->record(null, 'staff.mfa_reset', 'staff', $admin->id, PlatformAudit::RESULT_SUCCEEDED, [], '=HYPERLINK("x")');

        for ($i = 0; $i < 55; $i++) {
            $audit->record($admin->id, 'site.page.updated', 'public_page', 'page-'.$i, PlatformAudit::RESULT_SUCCEEDED);
        }

        Carbon::setTestNow('2026-10-20T10:05:00Z');
        $page = $this->getJson('/platform-api/v1/audit')->assertOk()->json();
        $this->assertCount(50, $page['data']);
        $this->assertNotNull($page['next_cursor']);
        $this->assertContains('tenant.suspend', $page['actions']);
        $this->assertSame('site.page.updated', $page['data'][0]['action']);
        $rest = $this->getJson('/platform-api/v1/audit?cursor='.$page['next_cursor'])->assertOk()->json();
        $this->assertNull($rest['next_cursor']);
        $this->assertSame(count($page['data']) + count($rest['data']), DB::table('platform_audit_log')->count());

        $suspends = $this->getJson("/platform-api/v1/audit?action=tenant.suspend&tenant={$tenant->id}")->assertOk()->json('data');
        $this->assertCount(2, $suspends);
        $this->assertSame('Kaffee GmbH', $suspends[0]['tenant']['name']);
        $this->assertSame('Staff', $suspends[0]['actor']['name']);
        $this->assertSame(['denied'], array_column($this->getJson('/platform-api/v1/audit?action=tenant.suspend&result=denied')->json('data'), 'result'));
        $this->assertSame(['Abuse report'], array_column($this->getJson('/platform-api/v1/audit?action=tenant.suspend&from=2026-10-18&to=2026-10-18')->json('data'), 'reason'));
        $system = $this->getJson('/platform-api/v1/audit?actor=system')->json('data');
        $this->assertSame(['staff.mfa_reset'], array_column($system, 'action'));
        $this->assertNull($system[0]['actor']);
        $this->getJson('/platform-api/v1/audit?result=maybe')->assertStatus(422);

        $export = $this->get('/platform-api/v1/audit/export?actor=system')->assertOk();
        $this->assertStringContainsString('text/csv', (string) $export->headers->get('Content-Type'));
        $csv = $export->streamedContent();
        $this->assertStringContainsString('staff.mfa_reset', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString('tenant.suspend', $csv);
        $this->assertSame(1, DB::table('platform_audit_log')->where('action', 'audit.exported')->where('actor_staff_id', $admin->id)->count());
    }

    public function test_support_cannot_read_the_platform_audit(): void
    {
        $this->signIn(PlatformStaff::ROLE_SUPPORT);
        $this->getJson('/platform-api/v1/audit')->assertForbidden();
        $this->get('/platform-api/v1/audit/export')->assertForbidden();
    }

    private function signIn(string $role): PlatformStaff
    {
        $user = User::query()->create(['name' => 'Staff', 'email' => 'staff@platform.test', 'password_hash' => Hash::make('staff-password-1234'), 'locale' => 'ru']);
        $mfa = app(MfaService::class);
        $this->secret = $mfa->beginSetup($user)['secret'];
        $mfa->confirm($user, $this->code());
        $staff = PlatformStaff::query()->create(['user_id' => $user->id, 'role' => $role, 'state' => PlatformStaff::STATE_ACTIVE]);
        $this->postJson('/platform-api/v1/auth/login', ['email' => 'staff@platform.test', 'password' => 'staff-password-1234'])->assertOk();
        $this->postJson('/platform-api/v1/auth/mfa', ['code' => $this->code()])->assertOk();

        return $staff;
    }

    private function code(): string
    {
        Carbon::setTestNow(Carbon::now()->addSeconds(30));
        $totp = new Totp;

        return $totp->codeAt($this->secret, $totp->step(Carbon::now()->getTimestamp()));
    }
}
