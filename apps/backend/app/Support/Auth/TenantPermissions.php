<?php

namespace App\Support\Auth;

use App\Models\Membership;

/**
 * What a member of a customer organisation may do (ADR 0079). The owner has everything, always; every other member
 * has exactly their own list. The former roles are presets that fill the list.
 */
final class TenantPermissions
{
    public const STORES_READ = 'stores.read';

    public const STORES_MANAGE = 'stores.manage';

    public const INTEGRATIONS_MANAGE = 'integrations.manage';

    public const CHECKS_RUN = 'checks.run';

    public const RECONCILIATION_READ = 'reconciliation.read';

    public const RECONCILIATION_RUN = 'reconciliation.run';

    public const ALLOCATIONS_MANAGE = 'allocations.manage';

    public const INCIDENTS_READ = 'incidents.read';

    public const INCIDENTS_MANAGE = 'incidents.manage';

    public const INCIDENTS_SNOOZE = 'incidents.snooze';

    public const NOTIFICATIONS_MANAGE = 'notifications.manage';

    public const TEAM_MANAGE = 'team.manage';

    public const MONEY_VIEW = 'money.view';

    public const BILLING_MANAGE = 'billing.manage';

    public const STORES_DELETE = 'stores.delete';

    public const OWNERSHIP_TRANSFER = 'ownership.transfer';

    public const MEMBERS_MFA_RESET = 'members.mfa_reset';

    public const SUPPORT_ACCESS = 'support.access';

    public const ORGANIZATION_DELETE = 'organization.delete';

    /** Every permission, grouped by cabinet section in the order the team page lists them. */
    public const SECTIONS = [
        'stores' => [self::STORES_READ, self::STORES_MANAGE, self::INTEGRATIONS_MANAGE, self::STORES_DELETE],
        'checks' => [self::CHECKS_RUN],
        'reconciliation' => [self::RECONCILIATION_READ, self::RECONCILIATION_RUN, self::ALLOCATIONS_MANAGE],
        'incidents' => [self::INCIDENTS_READ, self::INCIDENTS_MANAGE, self::INCIDENTS_SNOOZE],
        'notifications' => [self::NOTIFICATIONS_MANAGE],
        'money' => [self::MONEY_VIEW],
        'settings' => [self::TEAM_MANAGE, self::BILLING_MANAGE, self::OWNERSHIP_TRANSFER, self::MEMBERS_MFA_RESET, self::SUPPORT_ACCESS, self::ORGANIZATION_DELETE],
    ];

    /** Only the organisation owner has these; they are shown but cannot be given. */
    public const OWNER_ONLY = [self::STORES_DELETE, self::BILLING_MANAGE, self::OWNERSHIP_TRANSFER, self::MEMBERS_MFA_RESET, self::SUPPORT_ACCESS, self::ORGANIZATION_DELETE];

    /** Every member can see the stores: without it nothing in the cabinet opens. */
    public const BASE = [self::STORES_READ];

    /** A permission that is useless without another one brings it along. */
    public const IMPLIES = [
        self::RECONCILIATION_RUN => [self::RECONCILIATION_READ],
        self::ALLOCATIONS_MANAGE => [self::RECONCILIATION_READ],
        self::INCIDENTS_MANAGE => [self::INCIDENTS_READ],
        self::INCIDENTS_SNOOZE => [self::INCIDENTS_READ],
    ];

    /** The former fixed roles; seeing amounts is chosen separately and does not decide the preset. */
    public const PRESETS = [
        TenantRoles::ADMIN => [
            self::STORES_READ, self::STORES_MANAGE, self::INTEGRATIONS_MANAGE, self::CHECKS_RUN,
            self::RECONCILIATION_READ, self::RECONCILIATION_RUN, self::ALLOCATIONS_MANAGE,
            self::INCIDENTS_READ, self::INCIDENTS_MANAGE, self::INCIDENTS_SNOOZE,
            self::NOTIFICATIONS_MANAGE, self::TEAM_MANAGE,
        ],
        TenantRoles::OPERATOR => [self::STORES_READ, self::RECONCILIATION_READ, self::RECONCILIATION_RUN, self::INCIDENTS_READ, self::INCIDENTS_MANAGE],
        TenantRoles::VIEWER => [self::STORES_READ, self::RECONCILIATION_READ, self::INCIDENTS_READ],
    ];

    public const CUSTOM = 'custom';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_merge(...array_values(self::SECTIONS));
    }

    /**
     * @return list<string>
     */
    public static function assignable(): array
    {
        return array_values(array_diff(self::all(), self::OWNER_ONLY));
    }

    /**
     * @param  array<int, mixed>|null  $permissions
     * @return list<string>
     */
    public static function forRole(string $role, ?array $permissions): array
    {
        return $role === TenantRoles::OWNER ? self::all() : self::normalize((array) ($permissions ?? []));
    }

    /**
     * @return list<string>
     */
    public static function forMembership(?Membership $membership): array
    {
        return $membership === null ? [] : self::forRole((string) $membership->role, $membership->permissions);
    }

    public static function allows(?Membership $membership, string $permission): bool
    {
        return in_array($permission, self::forMembership($membership), true);
    }

    /**
     * Known, assignable permissions with the base and what they imply, in the page's order.
     *
     * @param  array<int, mixed>  $permissions
     * @return list<string>
     */
    public static function normalize(array $permissions): array
    {
        $set = [...self::BASE, ...array_filter($permissions, 'is_string')];

        foreach ($set as $permission) {
            $set = [...$set, ...(self::IMPLIES[$permission] ?? [])];
        }

        return array_values(array_intersect(self::assignable(), $set));
    }

    /**
     * @return list<string>
     */
    public static function preset(string $role, bool $moneyView): array
    {
        return self::normalize([...(self::PRESETS[$role] ?? []), ...($moneyView ? [self::MONEY_VIEW] : [])]);
    }

    /** The preset the list matches (ignoring amounts), otherwise a custom set. */
    public static function roleFor(array $permissions): string
    {
        $set = array_values(array_diff(self::normalize($permissions), [self::MONEY_VIEW]));

        foreach (self::PRESETS as $role => $preset) {
            $expected = self::normalize($preset);

            if ($set === $expected) {
                return $role;
            }
        }

        return self::CUSTOM;
    }
}
