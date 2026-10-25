<?php

namespace Tests\Feature\Platform;

use App\Models\ContactRequest;
use App\Models\PlatformStaff;
use App\Models\Store;
use App\Models\SupportAccessGrant;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Account\MfaService;
use App\Support\Account\Totp;
use App\Support\Platform\AuditPresentation;
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
        $this->assertStringContainsString('Abuse report', $this->getJson('/platform-api/v1/audit?action=tenant.suspend&from=2026-10-18&to=2026-10-18')->json('data.0.presentation.description'));
        $system = $this->getJson('/platform-api/v1/audit?actor=system')->json('data');
        $this->assertSame(['staff.mfa_reset'], array_column($system, 'action'));
        $this->assertNull($system[0]['actor']);
        $this->getJson('/platform-api/v1/audit?result=maybe')->assertStatus(422);

        $export = $this->get('/platform-api/v1/audit/export?actor=system')->assertOk();
        $this->assertStringContainsString('text/csv', (string) $export->headers->get('Content-Type'));
        $csv = $export->streamedContent();
        $this->assertStringContainsString('Аутентификатор сотрудника сброшен', $csv);
        $this->assertStringNotContainsString('=HYPERLINK', $csv);
        $this->assertStringNotContainsString('tenant.suspend', $csv);
        $this->assertStringNotContainsString('request_id', $csv);
        $this->assertSame(1, DB::table('platform_audit_log')->where('action', 'audit.exported')->where('actor_staff_id', $admin->id)->count());
    }

    public function test_support_cannot_read_the_platform_audit(): void
    {
        $this->signIn(PlatformStaff::ROLE_SUPPORT);
        $this->getJson('/platform-api/v1/audit')->assertForbidden();
        $this->get('/platform-api/v1/audit/export')->assertForbidden();
    }

    public function test_audit_only_staff_cannot_resolve_customer_names_in_page_or_csv(): void
    {
        $staff = $this->signIn(PlatformStaff::ROLE_ADMIN);
        $staff->forceFill(['capabilities' => ['audit.read']])->save();
        $tenant = Tenant::query()->create(['name' => 'Secret Tenant', 'locale' => 'de', 'timezone' => 'Europe/Berlin']);
        app(PlatformAudit::class)->record($staff->id, 'tenant.suspend', 'tenant', $tenant->id, PlatformAudit::RESULT_SUCCEEDED, [], 'Secret Tenant problem', $tenant->id);

        $page = $this->getJson('/platform-api/v1/audit?action=tenant.suspend')->assertOk()->json('data.0');
        $this->assertNull($page['tenant']);
        $this->assertSame('Доступ ограничен', $page['presentation']['object']['label']);
        $this->assertStringNotContainsString('Secret Tenant', $page['presentation']['description']);
        $csv = $this->get('/platform-api/v1/audit/export?action=tenant.suspend')->assertOk()->streamedContent();
        $this->assertStringNotContainsString('Secret Tenant', $csv);
    }

    public function test_csv_escapes_formula_in_an_authorized_object_name(): void
    {
        $owner = $this->signIn(PlatformStaff::ROLE_OWNER);
        $tenant = Tenant::query()->create(['name' => '=HYPERLINK("x")', 'locale' => 'de', 'timezone' => 'Europe/Berlin']);
        app(PlatformAudit::class)->record($owner->id, 'tenant.suspend', 'tenant', $tenant->id, PlatformAudit::RESULT_SUCCEEDED, [], null, $tenant->id);

        $csv = $this->get('/platform-api/v1/audit/export?action=tenant.suspend')->assertOk()->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
    }

    public function test_audit_describes_contact_changes_and_support_request_without_exposing_raw_metadata(): void
    {
        $owner = $this->signIn(PlatformStaff::ROLE_OWNER);
        $tenant = Tenant::query()->create(['name' => 'Kaffee GmbH', 'locale' => 'de', 'timezone' => 'Europe/Berlin']);
        $store = Store::query()->create(['tenant_id' => $tenant->id, 'name' => 'Main Shop', 'base_url' => 'https://shop.example.test', 'timezone' => 'Europe/Berlin', 'default_currency' => 'EUR']);
        $contact = ContactRequest::query()->create(['locale' => 'ru', 'name' => 'Ivan', 'email' => 'ivan@example.test', 'message' => 'Help', 'status' => 'closed']);
        $grant = SupportAccessGrant::query()->create(['tenant_id' => $tenant->id, 'store_id' => $store->id, 'staff_id' => $owner->id, 'requested_by_staff_id' => $owner->id, 'scopes' => ['read_diagnostics', 'read_artifacts'], 'duration_hours' => 24, 'reason' => 'Investigate', 'case_reference' => 'SUP-000001']);
        $audit = app(PlatformAudit::class);
        $audit->record($owner->id, 'site.contact.updated', 'contact_request', $contact->id, PlatformAudit::RESULT_SUCCEEDED, ['before' => ['status' => 'new'], 'after' => ['status' => 'closed']]);
        $audit->record($owner->id, 'site.contact.replied', 'contact_request', $contact->id, PlatformAudit::RESULT_SUCCEEDED, ['template' => 'answer', 'content_hash' => str_repeat('a', 64)]);
        $audit->record($owner->id, 'support_grant.requested', 'support_grant', $grant->id, PlatformAudit::RESULT_SUCCEEDED, ['store_id' => $store->id, 'duration_hours' => 24], 'Investigate', $tenant->id);

        $rows = $this->getJson('/platform-api/v1/audit')->assertOk()->json('data');
        $this->assertSame('SUP-000001', $rows[0]['presentation']['object']['label']);
        $this->assertCount(2, $rows[0]['presentation']['links']);
        $this->assertStringContainsString('/stores/'.$store->id, $rows[0]['presentation']['links'][0]['href']);
        $this->assertStringContainsString('Main Shop', $rows[0]['presentation']['description']);
        $this->assertStringContainsString('Kaffee GmbH', $rows[0]['presentation']['description']);
        $this->assertStringContainsString('24', $rows[0]['presentation']['description']);
        $this->assertStringContainsString('ivan@example.test', $rows[1]['presentation']['description']);
        $this->assertStringNotContainsString(str_repeat('a', 64), $rows[1]['presentation']['description']);
        $this->assertStringContainsString('Новое → Закрыто', $rows[2]['presentation']['description']);
        $this->assertArrayNotHasKey('changes', $rows[2]);
        $this->assertArrayNotHasKey('request_id', $rows[2]);

        $csv = $this->get('/platform-api/v1/audit/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('Новое → Закрыто', $csv);
        $this->assertStringNotContainsString('content_hash', $csv);
        $this->assertStringNotContainsString(str_repeat('a', 64), $csv);
    }

    public function test_legacy_change_does_not_invent_previous_value_and_names_are_permission_scoped(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Private Org', 'locale' => 'de', 'timezone' => 'Europe/Berlin']);
        $contact = ContactRequest::query()->create(['locale' => 'ru', 'name' => 'Ivan', 'email' => 'ivan@example.test', 'message' => 'Help', 'status' => 'closed']);
        $row = (object) ['action' => 'site.contact.updated', 'target_type' => 'contact_request', 'target_id' => $contact->id, 'target_tenant_id' => $tenant->id, 'sanitized_changes' => json_encode(['status' => 'closed']), 'reason' => 'Private note about Secret Tenant', 'result' => PlatformAudit::RESULT_SUCCEEDED];
        $limited = new PlatformStaff(['role' => PlatformStaff::ROLE_MEMBER, 'capabilities' => ['audit.read']]);
        $visible = app(AuditPresentation::class)->present($row, $limited, 'ru');
        $this->assertSame('Доступ ограничен', $visible['object']['label']);
        $this->assertStringNotContainsString('ivan@example.test', $visible['description']);
        $this->assertStringNotContainsString('Private Org', $visible['description']);
        $this->assertStringNotContainsString('Private note', $visible['description']);

        $owner = new PlatformStaff(['role' => PlatformStaff::ROLE_OWNER]);
        $legacy = app(AuditPresentation::class)->present($row, $owner, 'ru');
        $this->assertStringContainsString('Поле «Статус» изменено на «Закрыто»', $legacy['description']);
        $this->assertStringNotContainsString('→', $legacy['description']);
        $contact->delete();
        $deleted = app(AuditPresentation::class)->present($row, $owner, 'ru');
        $this->assertSame('Удалён', $deleted['object']['label']);
    }

    public function test_staff_permission_changes_use_translated_values(): void
    {
        $staff = $this->signIn(PlatformStaff::ROLE_OWNER);
        $row = (object) [
            'action' => 'staff.updated', 'target_type' => 'staff', 'target_id' => $staff->id, 'target_tenant_id' => null,
            'sanitized_changes' => json_encode(['before' => ['capabilities' => []], 'after' => ['capabilities' => ['tenants.read']]]),
            'reason' => null, 'result' => PlatformAudit::RESULT_SUCCEEDED,
        ];
        $presentation = app(AuditPresentation::class)->present($row, $staff, 'ru');
        $this->assertStringContainsString('Клиенты: просмотр', $presentation['description']);
        $this->assertStringNotContainsString('tenants.read', $presentation['description']);
    }

    public function test_page_draft_changes_show_readable_text_instead_of_html_or_hashes(): void
    {
        $staff = $this->signIn(PlatformStaff::ROLE_OWNER);
        $page = DB::table('public_pages')->where('kind', 'home')->first(['id']);
        $row = (object) [
            'action' => 'site.page.draft_saved', 'target_type' => 'public_page', 'target_id' => $page->id, 'target_tenant_id' => null,
            'sanitized_changes' => json_encode(['before' => ['title' => 'Old', 'body' => '<p>Before</p>'], 'after' => ['title' => 'New', 'body' => '<p>After</p>'], 'content_hash' => str_repeat('f', 64)]),
            'reason' => null, 'result' => PlatformAudit::RESULT_SUCCEEDED,
        ];
        $presentation = app(AuditPresentation::class)->present($row, $staff, 'ru');
        $this->assertStringContainsString('Заголовок: Old → New', $presentation['description']);
        $this->assertStringContainsString('Текст: Before → After', $presentation['description']);
        $this->assertStringNotContainsString('<p>', $presentation['description']);
        $this->assertStringNotContainsString(str_repeat('f', 64), $presentation['description']);
    }

    public function test_plan_entitlements_show_localized_features_and_limits(): void
    {
        $staff = $this->signIn(PlatformStaff::ROLE_OWNER);
        $row = (object) [
            'action' => 'plan_version.updated', 'target_type' => 'billing_plan_version', 'target_id' => 'missing', 'target_tenant_id' => null,
            'sanitized_changes' => json_encode(['before' => ['entitlements' => ['features' => [], 'limits' => ['max_active_stores' => 1]]], 'after' => ['entitlements' => ['features' => ['checkout_monitoring'], 'limits' => ['max_active_stores' => 2]]]]),
            'reason' => null, 'result' => PlatformAudit::RESULT_SUCCEEDED,
        ];
        $presentation = app(AuditPresentation::class)->present($row, $staff, 'ru');
        $this->assertStringContainsString('Проверка оформления заказа в браузере', $presentation['description']);
        $this->assertStringContainsString('Магазинов включено: 2', $presentation['description']);
        $this->assertStringNotContainsString('max_active_stores', $presentation['description']);
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
