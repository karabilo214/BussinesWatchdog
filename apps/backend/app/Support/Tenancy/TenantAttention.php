<?php

namespace App\Support\Tenancy;

use App\Models\Membership;
use App\Models\NotificationChannel;
use App\Models\Subscription;
use App\Models\SupportAccessGrant;
use App\Models\User;
use App\Support\Auth\TenantPermissions;
use App\Support\Auth\TenantRoles;

/**
 * Menu counters of the customer cabinet that the overview does not carry: notifications and settings. Only counts
 * and codes leave here, and only for what the member may open (ADR 0079); the level is the most urgent part.
 */
class TenantAttention
{
    public const CRIT = 'crit';

    public const WARN = 'warn';

    public const NOTE = 'note';

    private const RANK = [self::NOTE => 0, self::WARN => 1, self::CRIT => 2];

    /**
     * @return array{notifications: array<string, mixed>|null, settings: array<string, mixed>|null}
     */
    public function forMember(User $user, string $tenantId): array
    {
        $membership = Membership::query()->where('tenant_id', $tenantId)->where('user_id', $user->id)->first();

        return [
            'notifications' => $this->section($this->notifications($membership, $tenantId)),
            'settings' => $this->section($this->settings($membership, $user, $tenantId)),
        ];
    }

    /**
     * @return list<array{code: string, n: int, level: string}>
     */
    private function notifications(?Membership $membership, string $tenantId): array
    {
        if (! TenantPermissions::allows($membership, TenantPermissions::NOTIFICATIONS_MANAGE)) {
            return [];
        }

        $enabled = NotificationChannel::query()->where('tenant_id', $tenantId)->where('enabled', true);

        return [
            ['code' => 'channels_failing', 'level' => self::CRIT, 'n' => (clone $enabled)->whereNotNull('verified_at')->get(['health'])->filter(fn (NotificationChannel $channel): bool => ($channel->health['status'] ?? null) === 'failing')->count()],
            ['code' => 'channels_unverified', 'level' => self::WARN, 'n' => (clone $enabled)->whereNull('verified_at')->count()],
        ];
    }

    /**
     * @return list<array{code: string, n: int, level: string}>
     */
    private function settings(?Membership $membership, User $user, string $tenantId): array
    {
        $parts = [];

        if (TenantPermissions::allows($membership, TenantPermissions::BILLING_MANAGE)) {
            $parts[] = ['code' => 'subscription_past_due', 'level' => self::CRIT, 'n' => Subscription::query()->where('tenant_id', $tenantId)->where('status', Subscription::STATUS_PAST_DUE)->exists() ? 1 : 0];
        }

        if (TenantPermissions::allows($membership, TenantPermissions::SUPPORT_ACCESS)) {
            $parts[] = ['code' => 'support_requests_pending', 'level' => self::WARN, 'n' => SupportAccessGrant::query()->where('tenant_id', $tenantId)->where('state', SupportAccessGrant::PENDING)->count()];
        }

        if ($membership?->role === TenantRoles::OWNER) {
            $parts[] = ['code' => 'owner_mfa_off', 'level' => self::WARN, 'n' => $user->mfa_confirmed_at === null ? 1 : 0];
        }

        return $parts;
    }

    /**
     * @param  list<array{code: string, n: int, level: string}>  $parts
     * @return array{count: int, level: string, parts: list<array{code: string, n: int, level: string}>}|null
     */
    private function section(array $parts): ?array
    {
        $parts = array_values(array_filter($parts, fn (array $part): bool => $part['n'] > 0));

        if ($parts === []) {
            return null;
        }

        $level = array_reduce($parts, fn (string $carry, array $part): string => self::RANK[$part['level']] > self::RANK[$carry] ? $part['level'] : $carry, self::NOTE);

        return ['count' => array_sum(array_column($parts, 'n')), 'level' => $level, 'parts' => $parts];
    }
}
