<?php

namespace Tests\Feature\Stores;

use App\Models\Membership;
use App\Models\Store;
use App\Models\StoreVerification;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StoreVerificationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_dns_verification_challenge(): void
    {
        [$user, $tenant, $store] = $this->userWithStore();

        $response = $this->actingAs($user)
            ->withSession(['active_tenant_id' => $tenant->id])
            ->postJson("/api/v1/stores/{$store->id}/verify", [
                'method' => 'dns',
            ]);

        $response
            ->assertAccepted()
            ->assertJsonPath('store_id', $store->id)
            ->assertJsonPath('method', 'dns')
            ->assertJsonPath('state', 'pending')
            ->assertJsonPath('verified_origin', 'https://shop.example.test')
            ->assertJsonPath('instructions.type', 'dns_txt')
            ->assertJsonPath('instructions.host', 'shop.example.test')
            ->assertJsonMissingPath('challenge_hash');

        $challenge = (string) $response->json('challenge');

        $this->assertStringStartsWith('bw-', $challenge);
        $this->assertDatabaseHas('store_verifications', [
            'tenant_id' => $tenant->id,
            'store_id' => $store->id,
            'method' => 'dns',
            'challenge_hash' => hash('sha256', $challenge),
            'status' => 'pending',
        ]);
    }

    public function test_owner_can_create_plugin_verification_challenge(): void
    {
        [$user, $tenant, $store] = $this->userWithStore();

        $this->actingAs($user)
            ->withSession(['active_tenant_id' => $tenant->id])
            ->postJson("/api/v1/stores/{$store->id}/verify", [
                'method' => 'plugin_challenge',
            ])
            ->assertAccepted()
            ->assertJsonPath('method', 'plugin_challenge')
            ->assertJsonPath('instructions.type', 'plugin_challenge')
            ->assertJsonPath('instructions.path', '/.well-known/business-watchdog-verification.txt');
    }

    public function test_verification_method_is_validated(): void
    {
        [$user, $tenant, $store] = $this->userWithStore();

        $this->actingAs($user)
            ->withSession(['active_tenant_id' => $tenant->id])
            ->postJson("/api/v1/stores/{$store->id}/verify", [
                'method' => 'email',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['method']);
    }

    public function test_viewer_cannot_create_verification_challenge(): void
    {
        [$user, $tenant, $store] = $this->userWithStore('viewer');

        $this->actingAs($user)
            ->withSession(['active_tenant_id' => $tenant->id])
            ->postJson("/api/v1/stores/{$store->id}/verify", [
                'method' => 'dns',
            ])
            ->assertForbidden();
    }

    public function test_user_cannot_verify_foreign_store(): void
    {
        [$user, $tenant] = $this->userWithStore();
        $foreignTenant = Tenant::query()->create([
            'name' => 'Foreign Tenant',
            'timezone' => 'Europe/Kyiv',
        ]);
        $foreignStore = Store::query()->create([
            'tenant_id' => $foreignTenant->id,
            'name' => 'Foreign Shop',
            'base_url' => 'https://foreign.example.test',
            'timezone' => 'Europe/Kyiv',
            'default_currency' => 'EUR',
        ]);

        $this->actingAs($user)
            ->withSession(['active_tenant_id' => $tenant->id])
            ->postJson("/api/v1/stores/{$foreignStore->id}/verify", [
                'method' => 'dns',
            ])
            ->assertNotFound();
    }

    public function test_user_can_read_latest_verification_state(): void
    {
        [$user, $tenant, $store] = $this->userWithStore('operator');
        StoreVerification::query()->create([
            'tenant_id' => $tenant->id,
            'store_id' => $store->id,
            'method' => 'dns',
            'challenge_hash' => hash('sha256', 'old'),
            'verified_origin' => $store->base_url,
            'status' => 'failed',
            'expires_at' => now()->addMinutes(20),
            'created_at' => now()->subMinute(),
        ]);
        $latest = StoreVerification::query()->create([
            'tenant_id' => $tenant->id,
            'store_id' => $store->id,
            'method' => 'plugin_challenge',
            'challenge_hash' => hash('sha256', 'latest'),
            'verified_origin' => $store->base_url,
            'status' => 'pending',
            'expires_at' => now()->addMinutes(30),
            'created_at' => now(),
        ]);

        $this->actingAs($user)
            ->withSession(['active_tenant_id' => $tenant->id])
            ->getJson("/api/v1/stores/{$store->id}/verification")
            ->assertOk()
            ->assertJsonPath('id', $latest->id)
            ->assertJsonPath('state', 'pending')
            ->assertJsonMissingPath('challenge_hash');
    }

    public function test_pending_verification_expires_when_read_after_expiration(): void
    {
        [$user, $tenant, $store] = $this->userWithStore();
        $verification = StoreVerification::query()->create([
            'tenant_id' => $tenant->id,
            'store_id' => $store->id,
            'method' => 'dns',
            'challenge_hash' => hash('sha256', 'expired'),
            'verified_origin' => $store->base_url,
            'status' => 'pending',
            'expires_at' => now()->subMinute(),
            'created_at' => now()->subMinutes(31),
        ]);

        $this->actingAs($user)
            ->withSession(['active_tenant_id' => $tenant->id])
            ->getJson("/api/v1/stores/{$store->id}/verification")
            ->assertOk()
            ->assertJsonPath('id', $verification->id)
            ->assertJsonPath('state', 'expired');

        $this->assertDatabaseHas('store_verifications', [
            'id' => $verification->id,
            'status' => 'expired',
        ]);
    }

    /**
     * @return array{0: User, 1: Tenant, 2: Store}
     */
    private function userWithStore(string $role = 'owner'): array
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
            'role' => $role,
        ]);
        $store = Store::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Main Shop',
            'base_url' => 'https://shop.example.test',
            'timezone' => 'Europe/Kyiv',
            'default_currency' => 'EUR',
        ]);

        return [$user, $tenant, $store];
    }
}
