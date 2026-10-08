<?php

namespace Tests\Feature\Integrations;

use App\Models\AuditLog;
use App\Models\Integration;
use App\Models\IntegrationCredential;
use App\Models\Membership;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class IntegrationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_list_tenant_integrations_without_secrets(): void
    {
        [$user, $tenant, , $integration, $credential] = $this->userWithIntegration();

        $response = $this->actingAs($user)
            ->withSession(['active_tenant_id' => $tenant->id])
            ->getJson('/api/v1/integrations');

        $response
            ->assertOk()
            ->assertJsonPath('data.0.id', $integration->id)
            ->assertJsonPath('data.0.provider', 'custom_crm')
            ->assertJsonPath('data.0.credentials.0.key_id_suffix', substr($credential->key_id, -8))
            ->assertJsonMissingPath('data.0.credentials.0.ciphertext')
            ->assertJsonMissingPath('data.0.credentials.0.fingerprint');
    }

    public function test_user_can_filter_integrations_by_store(): void
    {
        [$user, $tenant, $store, $integration] = $this->userWithIntegration();
        $otherStore = Store::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Second Shop',
            'base_url' => 'https://second.example.test',
            'timezone' => 'Europe/Kyiv',
            'default_currency' => 'EUR',
        ]);
        $this->integration($tenant, $otherStore, provider: 'other_crm');

        $response = $this->actingAs($user)
            ->withSession(['active_tenant_id' => $tenant->id])
            ->getJson("/api/v1/integrations?store_id={$store->id}");

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $integration->id);
    }

    public function test_user_can_read_tenant_integration_detail(): void
    {
        [$user, $tenant, , $integration] = $this->userWithIntegration();

        $this->actingAs($user)
            ->withSession(['active_tenant_id' => $tenant->id])
            ->getJson("/api/v1/integrations/{$integration->id}")
            ->assertOk()
            ->assertJsonPath('id', $integration->id)
            ->assertJsonPath('status', Integration::STATUS_ACTIVE);
    }

    public function test_user_cannot_read_foreign_integration(): void
    {
        [$user, $tenant] = $this->userWithIntegration();
        [, , , $foreignIntegration] = $this->userWithIntegration();

        $this->actingAs($user)
            ->withSession(['active_tenant_id' => $tenant->id])
            ->getJson("/api/v1/integrations/{$foreignIntegration->id}")
            ->assertNotFound();
    }

    public function test_admin_can_revoke_integration_and_credentials_with_audit_log(): void
    {
        [$user, $tenant, , $integration, $credential] = $this->userWithIntegration(role: 'admin');

        $response = $this->actingAs($user)
            ->withSession(['active_tenant_id' => $tenant->id])
            ->postJson("/api/v1/integrations/{$integration->id}/revoke");

        $response
            ->assertOk()
            ->assertJsonPath('id', $integration->id)
            ->assertJsonPath('status', Integration::STATUS_REVOKED)
            ->assertJsonPath('credentials.0.status', IntegrationCredential::STATUS_REVOKED)
            ->assertJsonMissingPath('credentials.0.ciphertext');

        $this->assertDatabaseHas('integrations', [
            'id' => $integration->id,
            'status' => Integration::STATUS_REVOKED,
        ]);
        $this->assertDatabaseHas('integration_credentials', [
            'id' => $credential->id,
            'status' => IntegrationCredential::STATUS_REVOKED,
        ]);
        $this->assertDatabaseHas('audit_log', [
            'tenant_id' => $tenant->id,
            'store_id' => $integration->store_id,
            'actor_user_id' => $user->id,
            'actor_type' => AuditLog::ACTOR_USER,
            'action' => AuditLog::ACTION_INTEGRATION_REVOKED,
            'entity_type' => AuditLog::ENTITY_INTEGRATION,
            'entity_id' => $integration->id,
        ]);
    }

    public function test_viewer_cannot_revoke_integration(): void
    {
        [$user, $tenant, , $integration] = $this->userWithIntegration(role: 'viewer');

        $this->actingAs($user)
            ->withSession(['active_tenant_id' => $tenant->id])
            ->postJson("/api/v1/integrations/{$integration->id}/revoke")
            ->assertForbidden();

        $this->assertDatabaseHas('integrations', [
            'id' => $integration->id,
            'status' => Integration::STATUS_ACTIVE,
        ]);
    }

    /**
     * @return array{0: User, 1: Tenant, 2: Store, 3: Integration, 4: IntegrationCredential}
     */
    private function userWithIntegration(string $role = 'owner'): array
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
        $integration = $this->integration($tenant, $store);
        $credential = IntegrationCredential::query()->create([
            'tenant_id' => $tenant->id,
            'store_id' => $store->id,
            'integration_id' => $integration->id,
            'kind' => IntegrationCredential::KIND_PLUGIN_HMAC,
            'key_id' => 'bwk_'.fake()->lexify('????????????????'),
            'ciphertext' => Crypt::encryptString(base64_encode(random_bytes(32))),
            'key_version' => 1,
            'fingerprint' => hash('sha256', random_bytes(32)),
            'status' => IntegrationCredential::STATUS_ACTIVE,
            'created_at' => now(),
        ]);

        return [$user, $tenant, $store, $integration, $credential];
    }

    private function integration(Tenant $tenant, Store $store, string $provider = 'custom_crm'): Integration
    {
        return Integration::query()->create([
            'tenant_id' => $tenant->id,
            'store_id' => $store->id,
            'provider' => $provider,
            'install_id' => fake()->uuid(),
            'mode' => 'live',
            'source_authority' => Integration::SOURCE_STORE_REPORTED,
            'status' => Integration::STATUS_ACTIVE,
            'capabilities' => [],
            'connector_version' => '1.0.0',
            'health' => [],
        ]);
    }
}
