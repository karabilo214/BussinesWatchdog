<?php

namespace Tests\Feature\Tenancy;

use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TenantSwitchTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_activate_own_tenant(): void
    {
        [$user, $tenant] = $this->userWithTenant();

        $this->actingAs($user)
            ->postJson("/api/v1/tenants/{$tenant->id}/activate")
            ->assertOk()
            ->assertJsonPath('active_tenant_id', $tenant->id);

        $this->getJson('/api/v1/tenants/context')
            ->assertOk()
            ->assertJsonPath('active_tenant_id', $tenant->id)
            ->assertJsonPath('tenant_context_id', $tenant->id)
            ->assertJsonPath('tenant_context_source', 'session');
    }

    public function test_user_cannot_activate_foreign_tenant(): void
    {
        [$user] = $this->userWithTenant();
        $foreignTenant = Tenant::query()->create([
            'name' => 'Foreign Tenant',
            'timezone' => 'Europe/Kyiv',
        ]);

        $this->actingAs($user)
            ->postJson("/api/v1/tenants/{$foreignTenant->id}/activate")
            ->assertNotFound();
    }

    public function test_context_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/v1/tenants/context')->assertUnauthorized();
    }

    /**
     * @return array{0: User, 1: Tenant}
     */
    private function userWithTenant(): array
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

        return [$user, $tenant];
    }
}
