<?php

namespace Tests\Feature\Pairing;

use App\Models\AuditLog;
use App\Models\Integration;
use App\Models\Membership;
use App\Models\Order;
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
            ->assertJsonPath('service_url', url('/'))
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
        $this->assertDatabaseHas('audit_log', [
            'tenant_id' => $tenant->id,
            'store_id' => $store->id,
            'actor_type' => AuditLog::ACTOR_CONNECTOR,
            'action' => AuditLog::ACTION_INTEGRATION_PAIRED,
            'entity_type' => AuditLog::ENTITY_INTEGRATION,
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

    public function test_repairing_the_same_woocommerce_installation_keeps_existing_orders(): void
    {
        [, $tenant, $store] = $this->userWithStore();
        $installId = '550e8400-e29b-41d4-a716-446655440000';
        $old = Integration::query()->create([
            'tenant_id' => $tenant->id, 'store_id' => $store->id, 'provider' => 'woocommerce',
            'install_id' => $installId, 'mode' => 'live', 'source_authority' => Integration::SOURCE_STORE_REPORTED,
            'status' => Integration::STATUS_REVOKED, 'capabilities' => [], 'connector_version' => '0.3.1', 'health' => [],
        ]);
        $order = Order::query()->create([
            'tenant_id' => $tenant->id, 'store_id' => $store->id, 'integration_id' => $old->id,
            'external_id' => '41', 'display_number' => '41', 'source_revision' => 3,
            'status' => 'processing', 'mode' => 'live', 'currency' => 'EUR', 'currency_exponent' => 2,
            'total_minor' => 18400, 'payment_expected' => true, 'financial_support' => 'supported',
            'is_synthetic' => false, 'source_created_at' => now()->subDay(), 'source_updated_at' => now()->subDay(),
            'current_payload_hash' => str_repeat('a', 64), 'metadata' => [], 'created_at' => now()->subDay(), 'updated_at' => now()->subDay(),
        ]);
        $code = 'bwpc_woocommerce_repair_1234567890';
        $this->pairingCodeFor($store, $code);

        $response = $this->postJson('/api/v1/pairing/exchange', [
            'pairing_code' => $code, 'install_id' => $installId, 'connector_code' => 'woocommerce',
            'plugin_version' => '0.3.2', 'base_url' => $store->base_url,
        ])->assertCreated();

        $this->assertSame($order->id, Order::query()->where('integration_id', $response->json('integration_id'))->where('external_id', '41')->sole()->id);
        $this->assertSame(1, Order::query()->where('store_id', $store->id)->count());
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
