<?php

namespace Tests\Feature\Account;

use App\Mail\NotificationMessageMail;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantPermissions;
use App\Support\Auth\TenantRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

class TenantPermissionsTest extends TestCase
{
    use RefreshDatabase;

    /** Routes inside the signed-in tenant group that check membership themselves (ADR 0069 deletion, tenant switch). */
    private const WITHOUT_PERMISSION = [
        'POST api/v1/tenants/{tenant}/activate',
        'GET api/v1/tenants/context',
        'GET api/v1/organization-deletion',
        'POST api/v1/organization-deletion',
        'POST api/v1/organization-deletion/cancel',
    ];

    private Tenant $tenant;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->tenant = Tenant::query()->create(['name' => 'Kaffee GmbH', 'timezone' => 'Europe/Berlin', 'locale' => 'de']);
        $this->store = Store::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Main Shop', 'base_url' => 'https://shop.example.test', 'timezone' => 'Europe/Berlin', 'default_currency' => 'EUR']);
    }

    public function test_every_tenant_route_declares_the_permission_it_needs(): void
    {
        $unguarded = collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(fn (Route $route): bool => in_array('tenant.session', $route->gatherMiddleware(), true))
            ->reject(fn (Route $route): bool => collect($route->gatherMiddleware())->contains(fn (mixed $middleware): bool => is_string($middleware) && str_starts_with($middleware, 'tenant.can:')))
            ->flatMap(fn (Route $route): array => array_map(fn (string $method): string => $method.' '.$route->uri(), array_diff($route->methods(), ['HEAD'])))
            ->values()->all();

        $this->assertEqualsCanonicalizing(self::WITHOUT_PERMISSION, $unguarded);

        $declared = collect(RouteFacade::getRoutes()->getRoutes())
            ->flatMap(fn (Route $route): array => $route->gatherMiddleware())
            ->filter(fn (mixed $middleware): bool => is_string($middleware) && str_starts_with($middleware, 'tenant.can:'))
            ->map(fn (string $middleware): string => substr($middleware, strlen('tenant.can:')))
            ->unique()->values()->all();

        $this->assertSame([], array_values(array_diff($declared, TenantPermissions::all())));
    }

    public function test_presets_match_the_former_roles_and_amounts_do_not_decide_the_preset(): void
    {
        foreach ([TenantRoles::ADMIN, TenantRoles::OPERATOR, TenantRoles::VIEWER] as $role) {
            $this->assertSame($role, TenantPermissions::roleFor(TenantPermissions::preset($role, true)));
            $this->assertSame($role, TenantPermissions::roleFor(TenantPermissions::preset($role, false)));
        }

        $this->assertSame(TenantPermissions::CUSTOM, TenantPermissions::roleFor([TenantPermissions::INCIDENTS_READ]));
        $this->assertSame([TenantPermissions::STORES_READ, TenantPermissions::RECONCILIATION_READ, TenantPermissions::RECONCILIATION_RUN], TenantPermissions::normalize([TenantPermissions::RECONCILIATION_RUN, 'unknown', TenantPermissions::BILLING_MANAGE]));
        $this->assertSame(TenantPermissions::all(), TenantPermissions::forRole(TenantRoles::OWNER, null));
    }

    public function test_every_permission_is_named_in_every_language_and_the_migration_kept_the_former_roles(): void
    {
        foreach (['ru', 'en', 'de'] as $locale) {
            foreach (TenantPermissions::all() as $permission) {
                $key = 'account.permissions.'.str_replace('.', '_', $permission);
                $this->assertNotSame($key, trans($key, [], $locale), "{$locale}: {$permission}");
            }

            $this->assertNotSame('account.roles.custom', trans('account.roles.custom', [], $locale));
        }

        $migration = require database_path('migrations/2026_10_26_110000_add_permissions_to_memberships.php');
        $presets = (new \ReflectionClassConstant($migration, 'PRESETS'))->getValue();

        foreach (TenantPermissions::PRESETS as $role => $preset) {
            $this->assertSame(TenantPermissions::normalize($preset), TenantPermissions::normalize($presets[$role]), $role);
        }
    }

    public function test_sections_open_only_with_their_permission_and_a_change_applies_to_the_open_session(): void
    {
        $owner = $this->member(TenantRoles::OWNER, 'owner@example.test');
        $member = $this->member(TenantRoles::VIEWER, 'member@example.test', [TenantPermissions::STORES_READ]);

        $this->as($member)->getJson('/api/v1/stores')->assertOk();
        $this->as($member)->getJson('/api/v1/incidents')->assertForbidden();
        $this->as($member)->getJson("/api/v1/stores/{$this->store->id}/findings")->assertForbidden();
        $this->as($member)->postJson("/api/v1/stores/{$this->store->id}/reconciliations", [], ['Idempotency-Key' => 'k-1'])->assertForbidden();
        $this->as($member)->patchJson("/api/v1/stores/{$this->store->id}", ['name' => 'Renamed'])->assertForbidden();
        $this->as($member)->getJson('/api/v1/billing/subscription')->assertForbidden();
        $this->as($member)->getJson('/api/v1/overview')->assertOk()
            ->assertJsonPath('incidents', null)
            ->assertJsonPath('discrepancies', null)
            ->assertJsonPath('unknown_amount_incidents', null)
            ->assertJsonPath('money_amounts_hidden', true);
        $this->as($member)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('memberships.0.role', 'custom')->assertJsonPath('memberships.0.permissions', [TenantPermissions::STORES_READ]);

        $this->as($owner)->patchJson("/api/v1/memberships/{$member->id}", ['permissions' => [TenantPermissions::INCIDENTS_READ, TenantPermissions::RECONCILIATION_READ, TenantPermissions::MONEY_VIEW]])
            ->assertOk()->assertJsonPath('role', 'viewer');

        $this->as($member)->getJson('/api/v1/incidents')->assertOk();
        $this->as($member)->getJson("/api/v1/stores/{$this->store->id}/findings")->assertOk();
        $this->as($member)->getJson('/api/v1/overview')->assertOk()->assertJsonPath('incidents.active', 0)->assertJsonPath('money_amounts_hidden', false);
        $this->as($member)->getJson('/api/v1/money-visibility')->assertOk()->assertExactJson(['can_view_money_amounts' => true]);
    }

    public function test_incident_exports_need_incident_access_and_findings_exports_need_reconciliation_access(): void
    {
        $member = $this->member(TenantRoles::VIEWER, 'member@example.test', [TenantPermissions::RECONCILIATION_READ, TenantPermissions::MONEY_VIEW]);

        $this->as($member)->postJson("/api/v1/stores/{$this->store->id}/exports", ['kind' => 'incidents', 'format' => 'csv'], ['Idempotency-Key' => 'k-1'])->assertForbidden();
        $this->as($member)->postJson("/api/v1/stores/{$this->store->id}/exports", ['kind' => 'findings', 'format' => 'csv'], ['Idempotency-Key' => 'k-2'])->assertAccepted();
    }

    public function test_a_team_manager_gives_and_changes_only_what_they_have(): void
    {
        $owner = $this->member(TenantRoles::OWNER, 'owner@example.test');
        $manager = $this->member(TenantRoles::VIEWER, 'manager@example.test', [TenantPermissions::TEAM_MANAGE, TenantPermissions::INCIDENTS_READ, TenantPermissions::MONEY_VIEW]);
        $operator = $this->member(TenantRoles::OPERATOR, 'operator@example.test');
        $reader = $this->member(TenantRoles::VIEWER, 'reader@example.test', [TenantPermissions::STORES_READ]);

        $list = $this->as($manager)->getJson('/api/v1/memberships')->assertOk();
        $this->assertSame([TenantPermissions::STORES_READ, TenantPermissions::INCIDENTS_READ, TenantPermissions::MONEY_VIEW, TenantPermissions::TEAM_MANAGE], $list->json('assignable_permissions'));
        $this->assertSame([], $list->json('assignable_roles'));
        $this->assertSame([false, false, false, true], collect($list->json('data'))->pluck('can_edit')->all());

        $this->as($manager)->patchJson("/api/v1/memberships/{$reader->id}", ['permissions' => [TenantPermissions::INCIDENTS_READ]])->assertOk()->assertJsonPath('permissions', [TenantPermissions::STORES_READ, TenantPermissions::INCIDENTS_READ]);
        $this->as($manager)->patchJson("/api/v1/memberships/{$reader->id}", ['permissions' => [TenantPermissions::RECONCILIATION_READ]])->assertStatus(422)->assertJsonPath('code', 'permission_not_assignable');
        $this->as($manager)->patchJson("/api/v1/memberships/{$reader->id}", ['role' => 'viewer'])->assertStatus(422)->assertJsonPath('code', 'permission_not_assignable');
        $this->as($manager)->patchJson("/api/v1/memberships/{$operator->id}", ['permissions' => [TenantPermissions::INCIDENTS_READ]])->assertForbidden()->assertJsonPath('code', 'team_forbidden');
        $this->as($manager)->deleteJson("/api/v1/memberships/{$operator->id}")->assertForbidden();
        $this->as($manager)->patchJson("/api/v1/memberships/{$owner->id}", ['permissions' => [TenantPermissions::INCIDENTS_READ]])->assertForbidden();
        $this->as($manager)->patchJson("/api/v1/memberships/{$manager->id}", ['permissions' => [TenantPermissions::INCIDENTS_READ]])->assertStatus(422)->assertJsonPath('code', 'cannot_change_own_role');
        $this->as($reader)->patchJson("/api/v1/memberships/{$operator->id}", ['permissions' => [TenantPermissions::INCIDENTS_READ]])->assertForbidden();

        $this->as($owner)->patchJson("/api/v1/memberships/{$operator->id}", ['permissions' => [TenantPermissions::BILLING_MANAGE]])->assertStatus(422)->assertJsonPath('code', 'permission_not_assignable');
        $this->as($owner)->patchJson("/api/v1/memberships/{$operator->id}", ['permissions' => ['stores.read', 'nonsense']])->assertUnprocessable()->assertJsonValidationErrors(['permissions.1']);
    }

    public function test_a_change_is_audited_before_and_after_and_mailed_to_the_member(): void
    {
        $owner = $this->member(TenantRoles::OWNER, 'owner@example.test');
        $member = $this->member(TenantRoles::OPERATOR, 'member@example.test');
        $member->forceFill(['locale' => 'en'])->save();
        $before = TenantPermissions::preset(TenantRoles::OPERATOR, true);

        $this->as($owner)->patchJson("/api/v1/memberships/{$member->id}", ['permissions' => [...$before, TenantPermissions::CHECKS_RUN]])
            ->assertOk()->assertJsonPath('role', 'custom');

        $changes = json_decode((string) DB::table('audit_log')->where('action', 'membership.permissions_changed')->value('changes'), true);
        $this->assertSame(['role' => 'operator', 'permissions' => $before], $changes['before']);
        $this->assertSame('custom', $changes['after']['role']);
        $this->assertContains(TenantPermissions::CHECKS_RUN, $changes['after']['permissions']);
        Mail::assertSent(NotificationMessageMail::class, fn (NotificationMessageMail $mail): bool => $mail->hasTo('member@example.test')
            && str_contains($mail->subjectLine, 'Kaffee GmbH')
            && str_contains($mail->bodyText, "Added:\n— running checks and editing scenarios")
            && str_contains($mail->bodyText, "Removed:\n— none"));

        Mail::fake();
        $this->as($owner)->patchJson("/api/v1/memberships/{$member->id}", ['permissions' => [...$before, TenantPermissions::CHECKS_RUN]])->assertOk();
        Mail::assertNothingSent();
        $this->assertSame(1, DB::table('audit_log')->where('action', 'membership.permissions_changed')->count());
    }

    public function test_invitations_carry_permissions_that_can_be_corrected_until_accepted(): void
    {
        $owner = $this->member(TenantRoles::OWNER, 'owner@example.test');
        $manager = $this->member(TenantRoles::VIEWER, 'manager@example.test', [TenantPermissions::TEAM_MANAGE, TenantPermissions::INCIDENTS_READ]);

        $this->as($manager)->postJson('/api/v1/invitations', ['email' => 'new@example.test', 'permissions' => [TenantPermissions::INCIDENTS_MANAGE]])->assertStatus(422)->assertJsonPath('code', 'permission_not_assignable');
        $id = $this->as($owner)->postJson('/api/v1/invitations', ['email' => 'new@example.test', 'permissions' => [TenantPermissions::INCIDENTS_MANAGE]])
            ->assertCreated()->assertJsonPath('role', 'custom')->assertJsonPath('permissions', [TenantPermissions::STORES_READ, TenantPermissions::INCIDENTS_READ, TenantPermissions::INCIDENTS_MANAGE])->json('id');

        $this->as($manager)->patchJson("/api/v1/invitations/{$id}", ['permissions' => [TenantPermissions::INCIDENTS_READ]])->assertForbidden();
        $this->as($owner)->patchJson("/api/v1/invitations/{$id}", ['role' => 'viewer'])->assertOk()->assertJsonPath('role', 'viewer')->assertJsonPath('permissions', TenantPermissions::preset(TenantRoles::VIEWER, false));
        $this->assertDatabaseHas('audit_log', ['action' => 'invitation.permissions_changed', 'entity_id' => $id]);

        $invitee = User::query()->create(['name' => 'New', 'email' => 'new@example.test', 'password_hash' => Hash::make('very-secure-password'), 'locale' => 'ru']);
        $token = $this->tokenFor('new@example.test');
        $this->actingAs($invitee)->postJson('/api/v1/invitations/accept', ['token' => $token])->assertOk()->assertJsonPath('role', 'viewer');
        $this->assertSame(TenantPermissions::preset(TenantRoles::VIEWER, false), Membership::query()->where('user_id', $invitee->id)->firstOrFail()->permissions);

        $this->as($owner)->patchJson("/api/v1/invitations/{$id}", ['role' => 'admin'])->assertStatus(422)->assertJsonPath('code', 'invitation_invalid');
    }

    public function test_permissions_never_reach_another_organization(): void
    {
        $owner = $this->member(TenantRoles::OWNER, 'owner@example.test');
        $foreignTenant = Tenant::query()->create(['name' => 'Other', 'timezone' => 'Europe/Berlin']);
        $foreign = User::query()->create(['name' => 'Foreign', 'email' => 'foreign@example.test', 'password_hash' => Hash::make('very-secure-password'), 'locale' => 'ru']);
        Membership::query()->create(['tenant_id' => $foreignTenant->id, 'user_id' => $foreign->id, 'role' => TenantRoles::VIEWER]);
        $foreignInvitation = Invitation::query()->create(['tenant_id' => $foreignTenant->id, 'email' => 'x@example.test', 'role' => 'viewer', 'token_hash' => str_repeat('a', 64), 'invited_by' => $foreign->id, 'expires_at' => now()->addDay()]);

        $this->as($owner)->patchJson("/api/v1/memberships/{$foreign->id}", ['role' => 'admin'])->assertNotFound();
        $this->as($owner)->patchJson("/api/v1/invitations/{$foreignInvitation->id}", ['role' => 'admin'])->assertNotFound();
        $this->assertSame(TenantRoles::VIEWER, Membership::query()->where('user_id', $foreign->id)->value('role'));
    }

    /**
     * @param  list<string>|null  $permissions
     */
    private function member(string $role, string $email, ?array $permissions = null): User
    {
        $user = User::query()->create(['name' => ucfirst($role), 'email' => $email, 'password_hash' => Hash::make('very-secure-password'), 'locale' => 'ru']);
        $list = $permissions === null ? null : TenantPermissions::normalize($permissions);
        Membership::query()->create(['tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'role' => $list === null ? $role : TenantPermissions::roleFor($list), 'permissions' => $list]);

        return $user;
    }

    private function as(User $user): static
    {
        return $this->actingAs($user)->withSession(['active_tenant_id' => $this->tenant->id]);
    }

    private function tokenFor(string $email): string
    {
        $token = '';

        Mail::assertSent(NotificationMessageMail::class, function (NotificationMessageMail $mail) use ($email, &$token): bool {
            if ($mail->hasTo($email) && preg_match('#token=([A-Za-z0-9]+)#', $mail->bodyText, $matches)) {
                $token = $matches[1];

                return true;
            }

            return false;
        });

        return $token;
    }
}
