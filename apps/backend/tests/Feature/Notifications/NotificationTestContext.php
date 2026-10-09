<?php

namespace Tests\Feature\Notifications;

use App\Models\Integration;
use App\Models\Membership;
use App\Models\NotificationChannel;
use App\Models\Order;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;

trait NotificationTestContext
{
    /**
     * @return array{user: User, tenant: Tenant, store: Store, integration: Integration}
     */
    private function context(string $role = 'owner', ?Tenant $tenant = null, ?Store $store = null, ?Integration $integration = null): array
    {
        $user = User::query()->create([
            'name' => 'Notification Tester',
            'email' => fake()->unique()->safeEmail(),
            'password_hash' => Hash::make('very-secure-password'),
            'locale' => 'ru',
        ]);
        $tenant ??= Tenant::query()->create([
            'name' => fake()->company(),
            'locale' => 'ru',
            'timezone' => 'Europe/Kyiv',
        ]);
        Membership::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role' => $role,
        ]);
        $store ??= Store::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Main Shop',
            'base_url' => 'https://'.fake()->domainName(),
            'timezone' => 'Europe/Kyiv',
            'default_currency' => 'EUR',
        ]);
        $integration ??= Integration::query()->create([
            'tenant_id' => $tenant->id,
            'store_id' => $store->id,
            'provider' => 'stripe',
            'install_id' => fake()->uuid(),
            'mode' => 'live',
            'source_authority' => Integration::SOURCE_INDEPENDENT_PROVIDER,
            'status' => Integration::STATUS_ACTIVE,
            'capabilities' => [],
            'connector_version' => '1.0.0',
            'health' => [],
        ]);

        return compact('user', 'tenant', 'store', 'integration');
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $overrides
     */
    private function order(array $context, array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'tenant_id' => $context['tenant']->id,
            'store_id' => $context['store']->id,
            'integration_id' => $context['integration']->id,
            'external_id' => fake()->uuid(),
            'display_number' => '#15238',
            'source_revision' => 1,
            'status' => 'processing',
            'mode' => 'live',
            'currency' => 'EUR',
            'currency_exponent' => 2,
            'total_minor' => 18400,
            'payment_expected' => true,
            'financial_support' => 'supported',
            'is_synthetic' => false,
            'source_created_at' => now()->subHours(3),
            'source_updated_at' => now()->subHours(2),
            'current_payload_hash' => hash('sha256', fake()->uuid()),
            'metadata' => [],
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $preferences
     */
    private function verifiedEmailChannel(Tenant $tenant, string $email = 'alerts@example.test', array $preferences = [], bool $enabled = true): NotificationChannel
    {
        return NotificationChannel::query()->create([
            'tenant_id' => $tenant->id,
            'kind' => NotificationChannel::KIND_EMAIL,
            'destination_ciphertext' => Crypt::encryptString($email),
            'key_version' => 1,
            'label' => 'Ops inbox',
            'enabled' => $enabled,
            'verified_at' => now(),
            'preferences' => $preferences,
            'health' => [],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
