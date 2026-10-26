<?php

namespace Tests\Feature\Tenancy;

use App\Models\Membership;
use App\Models\NotificationChannel;
use App\Models\PlatformStaff;
use App\Models\Subscription;
use App\Models\SupportAccessGrant;
use App\Models\User;
use App\Support\Auth\TenantPermissions;
use App\Support\Auth\TenantRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Notifications\NotificationTestContext;
use Tests\TestCase;

class TenantAttentionTest extends TestCase
{
    use NotificationTestContext;
    use RefreshDatabase;

    public function test_the_owner_sees_notification_and_settings_counters_with_the_most_urgent_level(): void
    {
        $context = $this->context();
        $this->channel($context['tenant']->id, verified: true, health: ['status' => 'failing']);
        $this->channel($context['tenant']->id, verified: true, health: ['status' => 'ok']);
        $this->channel($context['tenant']->id, verified: false);
        $this->channel($context['tenant']->id, verified: false, enabled: false);
        Subscription::query()->create(['tenant_id' => $context['tenant']->id, 'provider' => 'paddle', 'plan_code' => 'pilot', 'entitlements' => [], 'status' => Subscription::STATUS_PAST_DUE]);
        $this->grant($context, SupportAccessGrant::PENDING);
        $this->grant($context, SupportAccessGrant::DENIED);

        $this->as($context)->getJson('/api/v1/attention')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertExactJson([
            'notifications' => ['count' => 2, 'level' => 'crit', 'parts' => [
                ['code' => 'channels_failing', 'level' => 'crit', 'n' => 1],
                ['code' => 'channels_unverified', 'level' => 'warn', 'n' => 1],
            ]],
            'settings' => ['count' => 3, 'level' => 'crit', 'parts' => [
                ['code' => 'subscription_past_due', 'level' => 'crit', 'n' => 1],
                ['code' => 'support_requests_pending', 'level' => 'warn', 'n' => 1],
                ['code' => 'owner_mfa_off', 'level' => 'warn', 'n' => 1],
            ]],
        ]);

        $context['user']->forceFill(['mfa_confirmed_at' => now()])->save();
        Subscription::query()->update(['status' => Subscription::STATUS_ACTIVE]);
        SupportAccessGrant::query()->update(['state' => SupportAccessGrant::DENIED]);
        NotificationChannel::query()->update(['enabled' => false]);

        $this->as($context)->getJson('/api/v1/attention')->assertOk()->assertExactJson(['notifications' => null, 'settings' => null]);
    }

    public function test_members_see_only_what_their_permissions_open_and_never_another_organization(): void
    {
        $owner = $this->context();
        $this->channel($owner['tenant']->id, verified: false);
        Subscription::query()->create(['tenant_id' => $owner['tenant']->id, 'provider' => 'paddle', 'plan_code' => 'pilot', 'entitlements' => [], 'status' => Subscription::STATUS_PAST_DUE]);
        $admin = $this->context(TenantRoles::ADMIN, $owner['tenant'], $owner['store'], $owner['integration']);
        $viewer = $this->context(TenantRoles::VIEWER, $owner['tenant'], $owner['store'], $owner['integration']);

        $this->as($admin)->getJson('/api/v1/attention')->assertOk()
            ->assertJsonPath('notifications.parts.0.code', 'channels_unverified')
            ->assertJsonPath('settings', null);
        $this->as($viewer)->getJson('/api/v1/attention')->assertOk()->assertExactJson(['notifications' => null, 'settings' => null]);

        Membership::query()->where('user_id', $viewer['user']->id)->update(['permissions' => json_encode(TenantPermissions::normalize([TenantPermissions::NOTIFICATIONS_MANAGE]))]);
        $this->as($viewer)->getJson('/api/v1/attention')->assertOk()->assertJsonPath('notifications.count', 1);

        $foreign = $this->context();
        $foreign['user']->forceFill(['mfa_confirmed_at' => now()])->save();
        $this->as($foreign)->getJson('/api/v1/attention')->assertOk()->assertExactJson(['notifications' => null, 'settings' => null]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function as(array $context): static
    {
        return $this->actingAs($context['user'])->withSession(['active_tenant_id' => $context['tenant']->id]);
    }

    /**
     * @param  array<string, mixed>  $health
     */
    private function channel(string $tenantId, bool $verified, array $health = [], bool $enabled = true): void
    {
        NotificationChannel::query()->create([
            'tenant_id' => $tenantId, 'kind' => NotificationChannel::KIND_EMAIL, 'destination_ciphertext' => Crypt::encryptString('alerts@example.test'), 'key_version' => 1,
            'label' => 'Ops', 'enabled' => $enabled, 'verified_at' => $verified ? now() : null, 'preferences' => [], 'health' => $health, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function grant(array $context, string $state): void
    {
        $staff = PlatformStaff::query()->create(['user_id' => User::query()->create(['name' => 'Staff', 'email' => fake()->unique()->safeEmail(), 'password_hash' => Hash::make('password'), 'locale' => 'ru'])->id, 'role' => PlatformStaff::ROLE_MEMBER, 'capabilities' => ['tenants.support'], 'state' => PlatformStaff::STATE_ACTIVE]);
        SupportAccessGrant::query()->create(['tenant_id' => $context['tenant']->id, 'store_id' => $context['store']->id, 'staff_id' => $staff->id, 'requested_by_staff_id' => $staff->id, 'scopes' => [SupportAccessGrant::READ_DIAGNOSTICS], 'reason' => 'Investigate', 'case_reference' => 'SUP-'.substr($staff->id, 0, 8), 'state' => $state, 'duration_hours' => 24]);
    }
}
