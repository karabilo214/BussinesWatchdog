<?php

namespace Tests\Feature\Stores;

use App\Models\Membership;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StoreApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_store_in_active_tenant(): void
    {
        [$user, $tenant] = $this->userWithTenant();

        $response = $this->actingAs($user)
            ->withSession(['active_tenant_id' => $tenant->id])
            ->postJson('/api/v1/stores', [
                'name' => 'Main Shop',
                'base_url' => 'https://shop.example.test/',
                'timezone' => 'Europe/Kyiv',
                'locale' => 'ru',
                'default_currency' => 'eur',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('name', 'Main Shop')
            ->assertJsonPath('base_url', 'https://shop.example.test')
            ->assertJsonPath('default_currency', 'EUR')
            ->assertJsonPath('status', 'onboarding');

        $this->assertDatabaseHas('stores', [
            'tenant_id' => $tenant->id,
            'name' => 'Main Shop',
            'base_url' => 'https://shop.example.test',
            'default_currency' => 'EUR',
        ]);
    }

    public function test_store_creation_requires_active_tenant_context(): void
    {
        [$user] = $this->userWithTenant();

        $this->actingAs($user)
            ->postJson('/api/v1/stores', [
                'name' => 'Main Shop',
                'base_url' => 'https://shop.example.test',
                'timezone' => 'Europe/Kyiv',
                'default_currency' => 'EUR',
            ])
            ->assertStatus(500);
    }

    public function test_user_lists_only_active_tenant_stores(): void
    {
        [$user, $tenant] = $this->userWithTenant();
        $otherTenant = Tenant::query()->create([
            'name' => 'Other Tenant',
            'timezone' => 'Europe/Kyiv',
        ]);
        $visible = Store::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Visible Shop',
            'base_url' => 'https://visible.example.test',
            'timezone' => 'Europe/Kyiv',
            'default_currency' => 'EUR',
        ]);
        Store::query()->create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Hidden Shop',
            'base_url' => 'https://hidden.example.test',
            'timezone' => 'Europe/Kyiv',
            'default_currency' => 'EUR',
        ]);

        $this->actingAs($user)
            ->withSession(['active_tenant_id' => $tenant->id])
            ->getJson('/api/v1/stores')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visible->id)
            ->assertJsonPath('data.0.name', 'Visible Shop');
    }

    public function test_user_cannot_read_foreign_store(): void
    {
        [$user, $tenant] = $this->userWithTenant();
        $otherTenant = Tenant::query()->create([
            'name' => 'Other Tenant',
            'timezone' => 'Europe/Kyiv',
        ]);
        $foreignStore = Store::query()->create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Hidden Shop',
            'base_url' => 'https://hidden.example.test',
            'timezone' => 'Europe/Kyiv',
            'default_currency' => 'EUR',
        ]);

        $this->actingAs($user)
            ->withSession(['active_tenant_id' => $tenant->id])
            ->getJson("/api/v1/stores/{$foreignStore->id}")
            ->assertNotFound();
    }

    public function test_store_creation_rejects_non_https_urls(): void
    {
        [$user, $tenant] = $this->userWithTenant();

        $this->actingAs($user)
            ->withSession(['active_tenant_id' => $tenant->id])
            ->postJson('/api/v1/stores', [
                'name' => 'Bad Shop',
                'base_url' => 'http://shop.example.test',
                'timezone' => 'Europe/Kyiv',
                'default_currency' => 'EUR',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['base_url']);
    }

    public function test_user_can_update_store_with_matching_version(): void
    {
        [$user, $tenant] = $this->userWithTenant();
        $store = Store::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Old Shop',
            'base_url' => 'https://old.example.test',
            'timezone' => 'Europe/Kyiv',
            'default_currency' => 'EUR',
        ]);

        $this->actingAs($user)
            ->withSession(['active_tenant_id' => $tenant->id])
            ->withHeader('If-Match', (string) $store->refresh()->config_version)
            ->patchJson("/api/v1/stores/{$store->id}", [
                'name' => 'Updated Shop',
                'timezone' => 'Europe/Berlin',
                'default_currency' => 'usd',
                'status' => 'active',
                'telemetry_enabled' => true,
            ])
            ->assertOk()
            ->assertJsonPath('name', 'Updated Shop')
            ->assertJsonPath('timezone', 'Europe/Berlin')
            ->assertJsonPath('default_currency', 'USD')
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('telemetry_enabled', true)
            ->assertJsonPath('config_version', 2);
    }

    public function test_store_update_rejects_stale_version(): void
    {
        [$user, $tenant] = $this->userWithTenant();
        $store = Store::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Old Shop',
            'base_url' => 'https://old.example.test',
            'timezone' => 'Europe/Kyiv',
            'default_currency' => 'EUR',
            'config_version' => 2,
        ]);

        $this->actingAs($user)
            ->withSession(['active_tenant_id' => $tenant->id])
            ->withHeader('If-Match', '1')
            ->patchJson("/api/v1/stores/{$store->id}", [
                'name' => 'Updated Shop',
            ])
            ->assertConflict()
            ->assertJsonPath('code', 'version_conflict');

        $this->assertDatabaseHas('stores', [
            'id' => $store->id,
            'name' => 'Old Shop',
            'config_version' => 2,
        ]);
    }

    public function test_store_update_requires_if_match_header(): void
    {
        [$user, $tenant] = $this->userWithTenant();
        $store = Store::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Old Shop',
            'base_url' => 'https://old.example.test',
            'timezone' => 'Europe/Kyiv',
            'default_currency' => 'EUR',
        ]);

        $this->actingAs($user)
            ->withSession(['active_tenant_id' => $tenant->id])
            ->patchJson("/api/v1/stores/{$store->id}", [
                'name' => 'Updated Shop',
            ])
            ->assertStatus(428);
    }

    public function test_store_base_url_change_resets_verification_and_browser_flag(): void
    {
        [$user, $tenant] = $this->userWithTenant();
        $store = Store::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Old Shop',
            'base_url' => 'https://old.example.test',
            'timezone' => 'Europe/Kyiv',
            'default_currency' => 'EUR',
            'verified_at' => now(),
            'browser_enabled' => true,
        ]);

        $this->actingAs($user)
            ->withSession(['active_tenant_id' => $tenant->id])
            ->withHeader('If-Match', (string) $store->refresh()->config_version)
            ->patchJson("/api/v1/stores/{$store->id}", [
                'base_url' => 'https://new.example.test/',
            ])
            ->assertOk()
            ->assertJsonPath('base_url', 'https://new.example.test')
            ->assertJsonPath('verified_at', null)
            ->assertJsonPath('browser_enabled', false);
    }

    public function test_user_cannot_update_foreign_store(): void
    {
        [$user, $tenant] = $this->userWithTenant();
        $otherTenant = Tenant::query()->create([
            'name' => 'Other Tenant',
            'timezone' => 'Europe/Kyiv',
        ]);
        $foreignStore = Store::query()->create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Hidden Shop',
            'base_url' => 'https://hidden.example.test',
            'timezone' => 'Europe/Kyiv',
            'default_currency' => 'EUR',
        ]);

        $this->actingAs($user)
            ->withSession(['active_tenant_id' => $tenant->id])
            ->withHeader('If-Match', (string) $foreignStore->refresh()->config_version)
            ->patchJson("/api/v1/stores/{$foreignStore->id}", [
                'name' => 'Updated Shop',
            ])
            ->assertNotFound();
    }

    /**
     * @return array{0: User, 1: Tenant}
     */
    private function userWithTenant(): array
    {
        $user = User::query()->create([
            'name' => 'Owner',
            'email' => fake()->unique()->safeEmail(),
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
