<?php

namespace Tests\Feature\Account;

use App\Mail\NotificationMessageMail;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class TeamApiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->tenant = Tenant::query()->create(['name' => 'Kaffee GmbH', 'timezone' => 'Europe/Berlin', 'locale' => 'de']);
    }

    public function test_members_are_listed_with_what_the_viewer_may_assign(): void
    {
        $owner = $this->member('owner');
        $this->member('viewer', 'viewer@example.test');

        $this->as($owner)->getJson('/api/v1/memberships')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.is_you', true)
            ->assertJsonPath('assignable_roles', ['admin', 'operator', 'viewer']);
    }

    public function test_role_rules_for_owner_and_admin(): void
    {
        $owner = $this->member('owner');
        $admin = $this->member('admin', 'admin@example.test');
        $operator = $this->member('operator', 'operator@example.test');
        $otherAdmin = $this->member('admin', 'admin2@example.test');

        $this->as($admin)->patchJson("/api/v1/memberships/{$operator->id}", ['role' => 'viewer'])->assertOk()->assertJsonPath('role', 'viewer');
        $this->as($admin)->patchJson("/api/v1/memberships/{$operator->id}", ['role' => 'admin'])->assertStatus(422)->assertJsonPath('code', 'role_not_assignable');
        $this->as($admin)->patchJson("/api/v1/memberships/{$otherAdmin->id}", ['role' => 'viewer'])->assertForbidden()->assertJsonPath('code', 'team_forbidden');
        $this->as($admin)->patchJson("/api/v1/memberships/{$owner->id}", ['role' => 'viewer'])->assertForbidden();
        $this->as($admin)->patchJson("/api/v1/memberships/{$admin->id}", ['role' => 'viewer'])->assertStatus(422)->assertJsonPath('code', 'cannot_change_own_role');
        $this->as($owner)->patchJson("/api/v1/memberships/{$otherAdmin->id}", ['role' => 'operator'])->assertOk();
        $this->as($owner)->patchJson("/api/v1/memberships/{$operator->id}", ['role' => 'owner'])->assertUnprocessable();
        $this->as($operator)->patchJson("/api/v1/memberships/{$owner->id}", ['role' => 'viewer'])->assertForbidden();

        $this->assertDatabaseHas('audit_log', ['tenant_id' => $this->tenant->id, 'action' => 'membership.role_changed', 'entity_id' => $operator->id]);
    }

    public function test_removal_and_leaving(): void
    {
        $owner = $this->member('owner');
        $admin = $this->member('admin', 'admin@example.test');
        $viewer = $this->member('viewer', 'viewer@example.test');

        $this->as($admin)->deleteJson("/api/v1/memberships/{$owner->id}")->assertForbidden();
        $this->as($owner)->deleteJson("/api/v1/memberships/{$owner->id}")->assertStatus(422)->assertJsonPath('code', 'owner_cannot_leave');
        $this->as($viewer)->deleteJson("/api/v1/memberships/{$viewer->id}")->assertNoContent();
        $this->as($owner)->deleteJson("/api/v1/memberships/{$admin->id}")->assertNoContent();

        $this->assertSame(['owner'], Membership::query()->where('tenant_id', $this->tenant->id)->pluck('role')->all());
        $this->as($admin)->getJson('/api/v1/stores')->assertForbidden()->assertJsonPath('code', 'tenant_forbidden');
    }

    public function test_invitation_lifecycle_for_an_existing_account(): void
    {
        $owner = $this->member('owner');
        $invitee = User::query()->create(['name' => 'Invitee', 'email' => 'invitee@example.test', 'password_hash' => Hash::make('very-secure-password'), 'locale' => 'ru']);

        $this->as($owner)->postJson('/api/v1/invitations', ['email' => 'owner@example.test', 'role' => 'viewer'])->assertStatus(409)->assertJsonPath('code', 'already_member');
        $first = $this->as($owner)->postJson('/api/v1/invitations', ['email' => ' Invitee@Example.test ', 'role' => 'operator'])->assertCreated()->assertJsonPath('state', 'pending');
        $oldToken = $this->tokenFor('invitee@example.test');
        $this->as($owner)->postJson('/api/v1/invitations', ['email' => 'invitee@example.test', 'role' => 'viewer'])->assertCreated();
        $token = $this->tokenFor('invitee@example.test');

        $this->as($owner)->getJson('/api/v1/invitations')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.role', 'viewer');
        $this->getJson('/api/v1/invitations/lookup?token='.$oldToken)->assertStatus(422)->assertJsonPath('code', 'invitation_invalid');
        $this->getJson('/api/v1/invitations/lookup?token='.$token)
            ->assertOk()->assertJsonPath('tenant_name', 'Kaffee GmbH')->assertJsonPath('role', 'viewer')->assertJsonPath('account_exists', true);

        $this->as($owner)->postJson('/api/v1/invitations/accept', ['token' => $token])->assertStatus(422)->assertJsonPath('code', 'invitation_email_mismatch');
        $this->actingAs($invitee)->postJson('/api/v1/invitations/accept', ['token' => $token])->assertOk()->assertJsonPath('role', 'viewer');

        $this->assertNotNull($invitee->refresh()->email_verified_at);
        $this->as($invitee)->getJson('/api/v1/stores')->assertOk();
        $this->actingAs($invitee)->postJson('/api/v1/invitations/accept', ['token' => $token])->assertStatus(422);
        $this->assertSame('viewer', Membership::query()->where('user_id', $invitee->id)->value('role'));
        $this->assertNotNull($first->json('id'));
    }

    public function test_a_new_person_registers_through_the_invitation_without_creating_a_tenant(): void
    {
        $owner = $this->member('owner');
        $this->as($owner)->postJson('/api/v1/invitations', ['email' => 'new@example.test', 'role' => 'admin'])->assertCreated();
        $token = $this->tokenFor('new@example.test');
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/v1/auth/register', ['name' => 'New', 'email' => 'other@example.test', 'password' => 'very-secure-password', 'password_confirmation' => 'very-secure-password', 'invitation_token' => $token])
            ->assertStatus(422)->assertJsonPath('code', 'invitation_email_mismatch');

        $this->postJson('/api/v1/auth/register', ['name' => 'New', 'email' => 'new@example.test', 'password' => 'very-secure-password', 'password_confirmation' => 'very-secure-password', 'invitation_token' => $token])
            ->assertCreated()
            ->assertJsonPath('active_tenant_id', $this->tenant->id)
            ->assertJsonPath('user.email_verified', true)
            ->assertJsonPath('memberships.0.role', 'admin');

        $this->assertSame(1, Tenant::query()->count());
    }

    public function test_admins_invite_only_operators_and_viewers_and_invitations_expire(): void
    {
        $this->member('owner');
        $admin = $this->member('admin', 'admin@example.test');

        $this->as($admin)->postJson('/api/v1/invitations', ['email' => 'x@example.test', 'role' => 'admin'])->assertStatus(422)->assertJsonPath('code', 'role_not_assignable');
        $this->as($admin)->postJson('/api/v1/invitations', ['email' => 'x@example.test', 'role' => 'operator'])->assertCreated();
        $token = $this->tokenFor('x@example.test');
        $this->travel(8)->days();

        $this->getJson('/api/v1/invitations/lookup?token='.$token)->assertStatus(422)->assertJsonPath('code', 'invitation_invalid');
        $this->as($admin)->getJson('/api/v1/invitations')->assertOk()->assertJsonCount(0, 'data');
    }

    private function member(string $role, string $email = 'owner@example.test'): User
    {
        $user = User::query()->create(['name' => ucfirst($role), 'email' => $email, 'password_hash' => Hash::make('very-secure-password'), 'locale' => 'ru']);
        Membership::query()->create(['tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'role' => $role]);

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
