<?php

namespace Tests\Feature\Pairing;

use App\Models\IntegrationCredential;
use App\Models\Membership;
use App\Models\PairingCode;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PairingApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_pairing_code_for_store(): void
    {
        [$user, $tenant, $store] = $this->userWithStore();

        $response = $this->actingAs($user)
            ->withSession(['active_tenant_id' => $tenant->id])
            ->postJson("/api/v1/stores/{$store->id}/pairing-codes");

        $response
            ->assertCreated()
            ->assertJsonPath('store_id', $store->id)
            ->assertJsonPath('saas_endpoint', url('/api/v1/pairing/exchange'))
            ->assertJsonMissingPath('code_hash');

        $pairingCode = (string) $response->json('pairing_code');

        $this->assertStringStartsWith('bwpc_', $pairingCode);
        $this->assertDatabaseHas('pairing_codes', [
            'tenant_id' => $tenant->id,
            'store_id' => $store->id,
            'code_hash' => hash('sha256', $pairingCode),
            'created_by' => $user->id,
        ]);
    }

    public function test_viewer_cannot_create_pairing_code(): void
    {
        [$user, $tenant, $store] = $this->userWithStore('viewer');

        $this->actingAs($user)
            ->withSession(['active_tenant_id' => $tenant->id])
            ->postJson("/api/v1/stores/{$store->id}/pairing-codes")
            ->assertForbidden();
    }

    public function test_user_cannot_create_pairing_code_for_foreign_store(): void
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
            ->postJson("/api/v1/stores/{$foreignStore->id}/pairing-codes")
            ->assertNotFound();
    }

    public function test_connector_can_exchange_pairing_code_once_for_hmac_secret(): void
    {
        [, $tenant, $store] = $this->userWithStore();
        $pairingCode = $this->pairingCodeFor($store, 'bwpc_valid_pairing_code_1234567890');

        $response = $this->postJson('/api/v1/pairing/exchange', [
            'pairing_code' => 'bwpc_valid_pairing_code_1234567890',
            'install_id' => '550e8400-e29b-41d4-a716-446655440000',
            'connector_code' => 'custom_crm',
            'plugin_version' => '1.2.3',
            'base_url' => 'https://shop.example.test/',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('secret_encoding', 'base64')
            ->assertJsonPath('signature_version', 1);

        $secret = base64_decode((string) $response->json('secret'), true);

        $this->assertIsString($secret);
        $this->assertSame(32, strlen($secret));
        $this->assertDatabaseHas('pairing_codes', [
            'id' => $pairingCode->id,
            'attempt_count' => 1,
        ]);
        $this->assertNotNull($pairingCode->refresh()->consumed_at);
        $this->assertDatabaseHas('integrations', [
            'tenant_id' => $tenant->id,
            'store_id' => $store->id,
            'provider' => 'custom_crm',
            'install_id' => '550e8400-e29b-41d4-a716-446655440000',
            'connector_version' => '1.2.3',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('integration_credentials', [
            'key_id' => $response->json('key_id'),
            'kind' => 'plugin_hmac',
            'status' => 'active',
        ]);
        $this->assertDatabaseMissing('integration_credentials', [
            'ciphertext' => $response->json('secret'),
        ]);
    }

    public function test_pairing_code_cannot_be_exchanged_twice(): void
    {
        [, , $store] = $this->userWithStore();
        $this->pairingCodeFor($store, 'bwpc_one_time_pairing_code_1234567890');

        $payload = [
            'pairing_code' => 'bwpc_one_time_pairing_code_1234567890',
            'install_id' => '550e8400-e29b-41d4-a716-446655440000',
            'connector_code' => 'custom_crm',
            'plugin_version' => '1.2.3',
            'base_url' => 'https://shop.example.test',
        ];

        $this->postJson('/api/v1/pairing/exchange', $payload)->assertCreated();

        $this->postJson('/api/v1/pairing/exchange', $payload)
            ->assertConflict()
            ->assertJsonPath('code', 'pairing_consumed');
    }

    public function test_expired_pairing_code_is_rejected(): void
    {
        [, , $store] = $this->userWithStore();
        $this->pairingCodeFor($store, 'bwpc_expired_pairing_code_1234567890', expiresAt: now()->subMinute());

        $this->postJson('/api/v1/pairing/exchange', [
            'pairing_code' => 'bwpc_expired_pairing_code_1234567890',
            'install_id' => '550e8400-e29b-41d4-a716-446655440000',
            'connector_code' => 'custom_crm',
            'plugin_version' => '1.2.3',
            'base_url' => 'https://shop.example.test',
        ])
            ->assertStatus(410)
            ->assertJsonPath('code', 'pairing_expired');
    }

    public function test_pairing_exchange_requires_matching_store_base_url(): void
    {
        [, , $store] = $this->userWithStore();
        $this->pairingCodeFor($store, 'bwpc_mismatched_pairing_code_1234567890');

        $this->postJson('/api/v1/pairing/exchange', [
            'pairing_code' => 'bwpc_mismatched_pairing_code_1234567890',
            'install_id' => '550e8400-e29b-41d4-a716-446655440000',
            'connector_code' => 'custom_crm',
            'plugin_version' => '1.2.3',
            'base_url' => 'https://attacker.example.test',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'pairing_base_url_mismatch');
    }

    public function test_connector_code_is_platform_neutral_and_validated(): void
    {
        [, , $store] = $this->userWithStore();
        $this->pairingCodeFor($store, 'bwpc_connector_code_pairing_1234567890');

        $this->postJson('/api/v1/pairing/exchange', [
            'pairing_code' => 'bwpc_connector_code_pairing_1234567890',
            'install_id' => '550e8400-e29b-41d4-a716-446655440000',
            'connector_code' => 'Bad Connector!',
            'plugin_version' => '1.2.3',
            'base_url' => 'https://shop.example.test',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['connector_code']);
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

    private function pairingCodeFor(Store $store, string $code, mixed $expiresAt = null): PairingCode
    {
        $creator = User::query()->firstOrFail();

        return PairingCode::query()->create([
            'tenant_id' => $store->tenant_id,
            'store_id' => $store->id,
            'code_hash' => hash('sha256', $code),
            'created_by' => $creator->id,
            'expires_at' => $expiresAt ?? now()->addMinutes(15),
            'created_at' => now(),
        ]);
    }
}
