<?php

namespace Tests\Feature\Tenancy;

use App\Models\Integration;
use App\Models\NotificationChannel;
use App\Models\Store;
use App\Support\Auth\TenantRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\Feature\Notifications\NotificationTestContext;
use Tests\TestCase;

class OrganizationSummaryTest extends TestCase
{
    use NotificationTestContext;
    use RefreshDatabase;

    public function test_the_summary_lists_providers_per_store_and_channels_for_those_who_manage_them(): void
    {
        $owner = $this->context();
        $owner['tenant']->forceFill(['name' => 'Kaffee GmbH', 'locale' => 'de', 'timezone' => 'Europe/Berlin'])->save();
        $owner['user']->forceFill(['first_name' => 'Anna', 'last_name' => 'Müller'])->save();
        $second = Store::query()->create(['tenant_id' => $owner['tenant']->id, 'name' => 'Second', 'base_url' => 'https://second.example.test', 'timezone' => 'Europe/Berlin', 'default_currency' => 'EUR']);
        $gone = Store::query()->create(['tenant_id' => $owner['tenant']->id, 'name' => 'Gone', 'base_url' => 'https://gone.example.test', 'timezone' => 'Europe/Berlin', 'default_currency' => 'EUR', 'status' => 'deleted']);
        $this->provider($owner['tenant']->id, $second->id, 'stripe', Integration::STATUS_DEGRADED);
        $this->provider($owner['tenant']->id, $second->id, 'paypal', Integration::STATUS_REVOKED);
        $this->provider($owner['tenant']->id, $gone->id, 'paypal', Integration::STATUS_ACTIVE);
        $this->channel($owner['tenant']->id, verified: true, health: ['status' => 'failing']);
        $this->channel($owner['tenant']->id, verified: false);
        $foreign = $this->context();
        $this->provider($foreign['tenant']->id, $foreign['store']->id, 'paypal', Integration::STATUS_ACTIVE);

        $this->as($owner)->getJson('/api/v1/organization')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('name', 'Kaffee GmbH')
            ->assertJsonPath('locale', 'de')
            ->assertJsonPath('timezone', 'Europe/Berlin')
            ->assertJsonPath('owner_name', 'Anna Müller')
            ->assertJsonPath('created_at', $owner['tenant']->created_at->toJSON())
            ->assertJsonPath('payment_providers', [['provider' => 'stripe', 'stores' => 2, 'attention' => 1]])
            ->assertJsonPath('notification_channels', ['enabled' => 2, 'unverified' => 1, 'failing' => 1]);

        $viewer = $this->context(TenantRoles::VIEWER, $owner['tenant'], $owner['store'], $owner['integration']);
        $this->as($viewer)->getJson('/api/v1/organization')->assertOk()
            ->assertJsonPath('owner_name', 'Anna Müller')
            ->assertJsonPath('payment_providers.0.provider', 'stripe')
            ->assertJsonPath('notification_channels', null);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function as(array $context): static
    {
        return $this->actingAs($context['user'])->withSession(['active_tenant_id' => $context['tenant']->id]);
    }

    private function provider(string $tenantId, string $storeId, string $provider, string $status): void
    {
        Integration::query()->create([
            'tenant_id' => $tenantId, 'store_id' => $storeId, 'provider' => $provider, 'install_id' => fake()->uuid(), 'mode' => 'live',
            'source_authority' => Integration::SOURCE_INDEPENDENT_PROVIDER, 'status' => $status, 'capabilities' => [], 'connector_version' => '1.0.0', 'health' => [],
        ]);
    }

    /**
     * @param  array<string, mixed>  $health
     */
    private function channel(string $tenantId, bool $verified, array $health = []): void
    {
        NotificationChannel::query()->create([
            'tenant_id' => $tenantId, 'kind' => NotificationChannel::KIND_EMAIL, 'destination_ciphertext' => Crypt::encryptString('alerts@example.test'), 'key_version' => 1,
            'label' => 'Ops', 'enabled' => true, 'verified_at' => $verified ? now() : null, 'preferences' => [], 'health' => $health, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
