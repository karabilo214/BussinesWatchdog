<?php

namespace Tests\Feature\Auth;

use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_creates_user_tenant_and_owner_membership(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Vladimir',
            'email' => ' OWNER@EXAMPLE.TEST ',
            'password' => 'very-secure-password',
            'password_confirmation' => 'very-secure-password',
            'organization_name' => 'Demo Store',
            'timezone' => 'Europe/Kyiv',
            'locale' => 'ru',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('user.email', 'owner@example.test')
            ->assertJsonPath('memberships.0.role', 'owner');

        $user = User::query()->where('email', 'owner@example.test')->firstOrFail();
        $tenant = Tenant::query()->where('name', 'Demo Store')->firstOrFail();

        $this->assertAuthenticatedAs($user);
        $this->assertSame($tenant->id, $response->json('active_tenant_id'));
        $this->assertDatabaseHas('memberships', [
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
    }

    public function test_login_and_me_return_membership_context(): void
    {
        $user = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password_hash' => Hash::make('very-secure-password'),
            'locale' => 'ru',
        ]);
        $tenant = Tenant::query()->create([
            'name' => 'Demo Store',
            'timezone' => 'Europe/Kyiv',
        ]);
        Membership::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'owner@example.test',
            'password' => 'very-secure-password',
        ])->assertOk()
            ->assertJsonPath('active_tenant_id', $tenant->id);

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('memberships.0.tenant_id', $tenant->id)
            ->assertJsonPath('memberships.0.role', 'owner');
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_logout_invalidates_session(): void
    {
        $user = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password_hash' => Hash::make('very-secure-password'),
            'locale' => 'ru',
        ]);

        $this->actingAs($user)
            ->postJson('/api/v1/auth/logout')
            ->assertNoContent();

        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }
}
