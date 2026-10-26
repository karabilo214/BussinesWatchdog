<?php

namespace Tests\Feature\Platform;

use App\Mail\NotificationMessageMail;
use App\Models\PlatformStaff;
use App\Models\SupportAccessGrant;
use App\Models\User;
use App\Support\Account\MfaService;
use App\Support\Account\Totp;
use App\Support\Platform\SupportGrants;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Notifications\NotificationTestContext;
use Tests\TestCase;

class SupportGrantTest extends TestCase
{
    use NotificationTestContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-23T10:00:00Z');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_request_approval_scope_expiry_and_revocation_guard_each_request(): void
    {
        $customer = $this->context();
        $other = $this->context();
        $staff = $this->signInStaff();
        Mail::fake();
        $tenant = $customer['tenant']->id;
        $store = $customer['store']->id;
        $detail = "/platform-api/v1/tenants/{$tenant}/stores/{$store}";

        $this->getJson($detail)->assertForbidden();
        $request = ['tenant_id' => $tenant, 'store_id' => $store, 'scopes' => ['read_diagnostics'], 'reason' => 'Investigate case 42'];
        $this->postJson('/platform-api/v1/support-grants', [...$request, 'case_reference' => 'MANUAL-42'])->assertStatus(422)->assertJsonValidationErrors('case_reference');
        $grantId = $this->postJson('/platform-api/v1/support-grants', $request)->assertCreated()->assertJsonPath('state', 'pending')->assertJsonPath('duration_hours', 24)->assertJsonPath('case_reference', 'SUP-000001')->json('id');
        $this->postJson('/platform-api/v1/support-grants', $request)->assertCreated()->assertJsonPath('id', $grantId);
        $this->assertSame(1, SupportAccessGrant::query()->count());
        $this->assertSame(2, DB::table('support_case_counters')->where('name', 'support_access')->value('next_number'));
        Mail::assertSent(NotificationMessageMail::class, 1);
        Mail::assertSent(NotificationMessageMail::class, fn (NotificationMessageMail $mail): bool => str_contains($mail->bodyText, 'SUP-000001') && str_contains($mail->bodyText, '/app/settings/support'));
        $second = app(SupportGrants::class)->request($other['tenant']->id, $other['store']->id, $staff, ['read_diagnostics'], 24, 'Investigate another store', null);
        $this->assertSame('SUP-000002', $second->case_reference);
        $this->getJson($detail)->assertForbidden();

        $this->actingAs($other['user'])->withSession(['active_tenant_id' => $other['tenant']->id])
            ->postJson("/api/v1/support-grants/{$grantId}/approve")->assertNotFound();
        $this->actingAs($customer['user'])->withSession(['active_tenant_id' => $tenant])
            ->postJson("/api/v1/support-grants/{$grantId}/approve")->assertOk()->assertJsonPath('state', 'approved');
        $this->assertSame('2026-10-24T10:00:30.000000Z', SupportAccessGrant::query()->findOrFail($grantId)->expires_at->toJSON());

        $grants = app(SupportGrants::class);
        $this->assertNotNull($grants->active($staff, $tenant, $store, SupportAccessGrant::READ_DIAGNOSTICS));
        $this->assertNull($grants->active($staff, $other['tenant']->id, $other['store']->id, SupportAccessGrant::READ_DIAGNOSTICS));
        $this->assertNull($grants->active($staff, $tenant, $store, SupportAccessGrant::READ_ARTIFACTS));
        $grants->require($staff, $tenant, $store, SupportAccessGrant::READ_DIAGNOSTICS, 'store', $store);
        Carbon::setTestNow(Carbon::now()->addHours(25));
        $this->assertNull($grants->active($staff, $tenant, $store, SupportAccessGrant::READ_DIAGNOSTICS));
        Carbon::setTestNow(Carbon::now()->subHours(25));
        $this->getJson("/api/v1/support-grants/{$grantId}/activity")->assertOk()->assertJsonFragment(['action' => 'support_access.opened', 'staff_name' => $staff->user->name]);
        $this->assertDatabaseHas('platform_audit_log', ['actor_staff_id' => $staff->id, 'action' => 'support_access.opened', 'target_tenant_id' => $tenant]);

        $this->postJson("/api/v1/support-grants/{$grantId}/revoke")->assertOk()->assertJsonPath('state', 'revoked');
        $this->assertNull($grants->active($staff, $tenant, $store, SupportAccessGrant::READ_DIAGNOSTICS));
        $this->postJson("/api/v1/support-grants/{$grantId}/revoke")->assertOk()->assertJsonPath('state', 'revoked');
    }

    public function test_store_name_edit_requires_its_own_live_grant_and_records_both_audits(): void
    {
        $customer = $this->context();
        $other = $this->context();
        $staff = $this->signInStaff();
        $tenant = $customer['tenant']->id;
        $store = $customer['store']->id;
        $path = "/platform-api/v1/tenants/{$tenant}/stores/{$store}/name";
        $body = ['current_name' => 'Main Shop', 'name' => 'Renamed Shop', 'reason' => 'Correct customer store label'];

        $this->patchJson($path, $body)->assertForbidden();
        $read = app(SupportGrants::class)->request($tenant, $store, $staff, ['read_diagnostics'], 24, 'Diagnose store', null);
        app(SupportGrants::class)->decide($read, $customer['user'], true);
        $this->patchJson($path, $body)->assertForbidden();

        $edit = app(SupportGrants::class)->request($tenant, $store, $staff, [SupportAccessGrant::EDIT_STORE], 24, 'Correct store name', null);
        $this->patchJson($path, $body)->assertForbidden();
        app(SupportGrants::class)->decide($edit, $customer['user'], true);
        $this->assertContains($edit->id, array_column($this->getJson("/platform-api/v1/support-grants?tenant_id={$tenant}&active_only=1")->assertOk()->json('data'), 'id'));
        $this->patchJson("/platform-api/v1/tenants/{$tenant}/stores/{$other['store']->id}/name", $body)->assertNotFound();
        $this->patchJson($path, [...$body, 'name' => '   '])->assertStatus(422);
        $this->patchJson($path, [...$body, 'reason' => 'x'])->assertStatus(422);
        $beforeStore = DB::table('stores')->where('id', $store)->first();
        $this->patchJson($path, $body)->assertOk()->assertJsonPath('name', 'Renamed Shop');
        $afterStore = DB::table('stores')->where('id', $store)->first();
        $this->assertSame('Renamed Shop', $afterStore->name);
        $this->assertSame((int) $beforeStore->config_version + 1, (int) $afterStore->config_version);
        $this->assertSame([$beforeStore->base_url, $beforeStore->platform, $beforeStore->default_currency, $beforeStore->browser_enabled],
            [$afterStore->base_url, $afterStore->platform, $afterStore->default_currency, $afterStore->browser_enabled]);
        $this->assertDatabaseHas('platform_audit_log', ['actor_staff_id' => $staff->id, 'action' => 'tenant.store_updated', 'target_id' => $store, 'reason' => $body['reason']]);
        $entry = DB::table('audit_log')->where('tenant_id', $tenant)->where('action', 'store.support_renamed')->sole();
        $changes = json_decode($entry->changes, true);
        $this->assertSame(['name' => 'Main Shop'], $changes['before']);
        $this->assertSame(['name' => 'Renamed Shop'], $changes['after']);
        $this->assertSame($edit->id, $changes['grant_id']);
        $this->patchJson($path, $body)->assertStatus(409);

        SupportAccessGrant::query()->whereKey($edit->id)->update(['expires_at' => Carbon::now()->subSecond()]);
        $this->patchJson($path, ['current_name' => 'Renamed Shop', 'name' => 'After Expiry', 'reason' => 'Second correction'])->assertForbidden();
        $this->assertNotContains($edit->id, array_column($this->getJson("/platform-api/v1/support-grants?tenant_id={$tenant}&active_only=1")->assertOk()->json('data'), 'id'));
        SupportAccessGrant::query()->whereKey($edit->id)->update(['expires_at' => Carbon::now()->addHours(24)]);
        app(SupportGrants::class)->revoke($edit, $customer['user']);
        $this->patchJson($path, ['current_name' => 'Renamed Shop', 'name' => 'Another Name', 'reason' => 'Second correction'])->assertForbidden();
        $this->assertSame('Renamed Shop', DB::table('stores')->where('id', $store)->value('name'));
        $this->actingAs($customer['user'])->withSession(['active_tenant_id' => $tenant])
            ->getJson("/api/v1/support-grants/{$edit->id}/activity")->assertOk()
            ->assertJsonFragment(['action' => 'store.support_renamed', 'before_name' => 'Main Shop', 'after_name' => 'Renamed Shop', 'reason' => $body['reason']]);
    }

    public function test_customer_chooses_staff_from_the_list_but_only_the_owner_can_approve_and_duration_is_bounded(): void
    {
        $customer = $this->context();
        $member = $this->context('admin', $customer['tenant'], $customer['store'], $customer['integration']);
        $staff = $this->signInStaff();
        $tenant = $customer['tenant']->id;
        $input = [
            'store_id' => $customer['store']->id, 'staff_id' => $staff->id,
            'scopes' => ['read_diagnostics', 'read_artifacts'], 'duration_hours' => 72,
            'reason' => 'Please inspect case 17',
        ];

        $this->actingAs($member['user'])->withSession(['active_tenant_id' => $tenant])->postJson('/api/v1/support-grants', $input)->assertForbidden();
        $disabled = PlatformStaff::query()->create(['user_id' => User::query()->create(['name' => 'Former Agent', 'email' => 'former@platform.test', 'password_hash' => Hash::make('former-password-1234'), 'locale' => 'en'])->id, 'role' => PlatformStaff::ROLE_SUPPORT, 'capabilities' => $staff->capabilities, 'state' => PlatformStaff::STATE_DISABLED]);
        $noSupport = PlatformStaff::query()->create(['user_id' => User::query()->create(['name' => 'Billing Clerk', 'email' => 'billing@platform.test', 'password_hash' => Hash::make('billing-password-1234'), 'locale' => 'en'])->id, 'role' => PlatformStaff::ROLE_MEMBER, 'capabilities' => [], 'state' => PlatformStaff::STATE_ACTIVE]);

        $this->actingAs($member['user'])->withSession(['active_tenant_id' => $tenant])->getJson('/api/v1/support-staff')->assertForbidden();
        $this->actingAs($customer['user'])->withSession(['active_tenant_id' => $tenant]);
        $list = $this->getJson('/api/v1/support-staff')->assertOk()->assertExactJson(['data' => [['id' => $staff->id, 'name' => 'Support Agent']]]);
        $this->assertStringNotContainsString('@', (string) $list->getContent());
        $this->postJson('/api/v1/support-grants', [...$input, 'staff_id' => $disabled->id])->assertStatus(422);
        $this->postJson('/api/v1/support-grants', [...$input, 'staff_id' => $noSupport->id])->assertStatus(422);
        $this->postJson('/api/v1/support-grants', [...$input, 'staff_email' => $staff->user->email, 'staff_id' => null])->assertStatus(422);
        $this->postJson('/api/v1/support-grants', [...$input, 'case_reference' => 'MANUAL-17'])->assertStatus(422)->assertJsonValidationErrors('case_reference');
        $this->postJson('/api/v1/support-grants', [...$input, 'duration_hours' => 73])->assertStatus(422);
        $grantId = $this->postJson('/api/v1/support-grants', $input)->assertCreated()->assertJsonPath('state', 'pending')->assertJsonPath('case_reference', 'SUP-000001')->assertJsonPath('staff.name', $staff->user->name)->assertJsonMissingPath('staff.email')->json('id');
        $this->actingAs($member['user'])->withSession(['active_tenant_id' => $tenant])->postJson("/api/v1/support-grants/{$grantId}/approve")->assertForbidden();
        $this->actingAs($customer['user'])->withSession(['active_tenant_id' => $tenant])->postJson("/api/v1/support-grants/{$grantId}/approve")->assertOk();
        $this->assertDatabaseHas('support_access_grants', ['id' => $grantId, 'requested_by_user_id' => $customer['user']->id, 'approved_by' => $customer['user']->id]);
        $this->assertSame(1, DB::table('audit_log')->where('action', 'support_grant.approved')->where('entity_id', $grantId)->count());
    }

    public function test_staff_cancels_a_pending_request_and_sends_any_request_again(): void
    {
        $customer = $this->context();
        $staff = $this->signInStaff();
        Mail::fake();
        $tenant = $customer['tenant']->id;
        $request = ['tenant_id' => $tenant, 'store_id' => $customer['store']->id, 'scopes' => ['read_diagnostics', 'read_artifacts'], 'duration_hours' => 48, 'reason' => 'Investigate case 77'];
        $grantId = $this->postJson('/platform-api/v1/support-grants', $request)->assertCreated()->json('id');
        Mail::assertSent(NotificationMessageMail::class, 1);

        $this->postJson("/platform-api/v1/support-grants/{$grantId}/resend")->assertOk()->assertJsonPath('id', $grantId)->assertJsonPath('state', 'pending');
        Mail::assertSent(NotificationMessageMail::class, 2);
        $this->assertSame(1, SupportAccessGrant::query()->count());

        $this->deleteJson("/platform-api/v1/support-grants/{$grantId}")->assertStatus(409);
        $this->postJson("/platform-api/v1/support-grants/{$grantId}/cancel")->assertOk()->assertJsonPath('state', 'cancelled');
        $this->postJson("/platform-api/v1/support-grants/{$grantId}/cancel")->assertOk()->assertJsonPath('state', 'cancelled');
        $this->assertSame(1, DB::table('audit_log')->where('action', 'support_grant.cancelled')->where('entity_id', $grantId)->count());
        $this->assertDatabaseHas('platform_audit_log', ['actor_staff_id' => $staff->id, 'action' => 'support_grant.cancelled', 'target_tenant_id' => $tenant]);
        try {
            app(SupportGrants::class)->decide(SupportAccessGrant::query()->findOrFail($grantId), $customer['user'], true);
            $this->fail('A cancelled request must not be approvable.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertNull(app(SupportGrants::class)->active($staff, $tenant, $customer['store']->id, SupportAccessGrant::READ_DIAGNOSTICS));

        $again = $this->postJson("/platform-api/v1/support-grants/{$grantId}/resend")->assertCreated()->assertJsonPath('state', 'pending')
            ->assertJsonPath('case_reference', 'SUP-000002')->assertJsonPath('scopes', ['read_diagnostics', 'read_artifacts'])->assertJsonPath('duration_hours', 48)->assertJsonPath('reason', 'Investigate case 77')->json('id');
        $this->assertNotSame($grantId, $again);
        Mail::assertSent(NotificationMessageMail::class, 3);
        app(SupportGrants::class)->decide(SupportAccessGrant::query()->findOrFail($again), $customer['user'], true);
        $this->postJson("/platform-api/v1/support-grants/{$again}/cancel")->assertStatus(409);
        $this->deleteJson("/platform-api/v1/support-grants/{$again}")->assertStatus(409);

        $this->deleteJson("/platform-api/v1/support-grants/{$grantId}")->assertNoContent();
        $this->deleteJson("/platform-api/v1/support-grants/{$grantId}")->assertNoContent();
        $listed = collect($this->getJson("/platform-api/v1/support-grants?tenant_id={$tenant}")->assertOk()->json('data'))->pluck('id');
        $this->assertFalse($listed->contains($grantId));
        $this->assertTrue($listed->contains($again));
        $this->assertDatabaseHas('support_access_grants', ['id' => $grantId, 'state' => 'cancelled']);
        $this->assertDatabaseHas('platform_audit_log', ['actor_staff_id' => $staff->id, 'action' => 'support_grant.hidden', 'target_id' => $grantId]);

        Carbon::setTestNow(Carbon::now()->addMinutes(2));
        $otherUser = User::query()->create(['name' => 'Other Agent', 'email' => 'other@platform.test', 'password_hash' => Hash::make('other-password-1234'), 'locale' => 'en']);
        $other = PlatformStaff::query()->create(['user_id' => $otherUser->id, 'role' => PlatformStaff::ROLE_SUPPORT, 'capabilities' => $staff->capabilities, 'state' => PlatformStaff::STATE_ACTIVE]);
        $foreign = app(SupportGrants::class)->request($tenant, $customer['store']->id, $other, ['read_diagnostics'], 24, 'Somebody else asks', null);
        $this->postJson("/platform-api/v1/support-grants/{$foreign->id}/cancel")->assertNotFound();
        $this->postJson("/platform-api/v1/support-grants/{$foreign->id}/resend")->assertNotFound();
        $this->deleteJson("/platform-api/v1/support-grants/{$foreign->id}")->assertNotFound();
    }

    private function signInStaff(): PlatformStaff
    {
        $user = User::query()->create(['name' => 'Support Agent', 'email' => 'support@platform.test', 'password_hash' => Hash::make('support-password-1234'), 'locale' => 'en']);
        $mfa = app(MfaService::class);
        $secret = $mfa->beginSetup($user)['secret'];
        $totp = new Totp;
        $code = $totp->codeAt($secret, $totp->step(Carbon::now()->getTimestamp()));
        $mfa->confirm($user, $code);
        Carbon::setTestNow(Carbon::now()->addSeconds(30));
        $code = $totp->codeAt($secret, $totp->step(Carbon::now()->getTimestamp()));
        $staff = PlatformStaff::query()->create(['user_id' => $user->id, 'role' => PlatformStaff::ROLE_SUPPORT, 'state' => PlatformStaff::STATE_ACTIVE]);
        $this->postJson('/platform-api/v1/auth/login', ['email' => $user->email, 'password' => 'support-password-1234'])->assertOk();
        $this->postJson('/platform-api/v1/auth/mfa', ['code' => $code])->assertOk();

        return $staff->load('user');
    }
}
