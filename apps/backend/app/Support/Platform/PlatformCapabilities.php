<?php

namespace App\Support\Platform;

use App\Models\PlatformStaff;

final class PlatformCapabilities
{
    public const PLANS_MANAGE = 'plans.manage';

    public const PLANS_READ = 'plans.read';

    public const STAFF_MANAGE = 'staff.manage';

    public const AUDIT_READ = 'audit.read';

    public const TENANTS_READ = 'tenants.read';

    public const TENANTS_MANAGE = 'tenants.manage';

    public const CUSTOMER_MFA_RESET = 'customers.mfa_reset';

    public const BILLING_READ = 'billing.read';

    public const CONTENT_MANAGE = 'content.manage';

    public const CONTACTS_READ = 'contacts.read';

    public const CONTACTS_MANAGE = 'contacts.manage';

    public const REFUNDS_REQUEST = 'refunds.request';

    public const REFUNDS_APPROVE = 'refunds.approve';

    public const SERVICE_READ = 'service.read';

    public const SETTINGS_MANAGE = 'settings.manage';

    /** Every capability of the owner panel, in the order the panel lists them. */
    public const ALL = [
        self::TENANTS_READ, self::TENANTS_MANAGE, self::CUSTOMER_MFA_RESET,
        self::BILLING_READ, self::REFUNDS_REQUEST, self::REFUNDS_APPROVE,
        self::PLANS_READ, self::PLANS_MANAGE,
        self::CONTENT_MANAGE, self::CONTACTS_READ, self::CONTACTS_MANAGE,
        self::SERVICE_READ, self::AUDIT_READ, self::SETTINGS_MANAGE, self::STAFF_MANAGE,
    ];

    /**
     * Only platform owners have these (ADR 0064): managing staff (ADR 0052) and approving refunds (ADR 0044: staff
     * request, the platform owner approves).
     */
    public const OWNER_ONLY = [self::STAFF_MANAGE, self::REFUNDS_APPROVE];

    /** A capability that is useless without another one brings it along. */
    public const IMPLIES = [
        self::TENANTS_MANAGE => [self::TENANTS_READ],
        self::CUSTOMER_MFA_RESET => [self::TENANTS_READ],
        self::REFUNDS_REQUEST => [self::BILLING_READ],
        self::PLANS_MANAGE => [self::PLANS_READ],
        self::CONTACTS_MANAGE => [self::CONTACTS_READ],
    ];

    /** Starting points for a new member; the former fixed roles. */
    public const PRESETS = [
        PlatformStaff::ROLE_ADMIN => [self::PLANS_READ, self::TENANTS_READ, self::CUSTOMER_MFA_RESET, self::AUDIT_READ, self::CONTACTS_READ, self::SERVICE_READ],
        PlatformStaff::ROLE_SUPPORT => [self::TENANTS_READ, self::CUSTOMER_MFA_RESET, self::CONTACTS_READ, self::CONTACTS_MANAGE],
        PlatformStaff::ROLE_BILLING => [self::PLANS_READ, self::TENANTS_READ, self::BILLING_READ, self::REFUNDS_REQUEST],
    ];

    /**
     * @return list<string>
     */
    public static function assignable(): array
    {
        return array_values(array_diff(self::ALL, self::OWNER_ONLY));
    }

    /**
     * What a staff member may do: an owner everything, a member the set the owner gave them (never an owner-only one).
     *
     * @return list<string>
     */
    public static function forStaff(PlatformStaff $staff): array
    {
        return $staff->role === PlatformStaff::ROLE_OWNER ? self::ALL : self::normalize((array) ($staff->capabilities ?? []));
    }

    public static function allows(PlatformStaff $staff, string $capability): bool
    {
        return in_array($capability, self::forStaff($staff), true);
    }

    /**
     * Known, assignable capabilities with what they imply, in the panel's order.
     *
     * @param  array<int, mixed>  $capabilities
     * @return list<string>
     */
    public static function normalize(array $capabilities): array
    {
        $set = array_values(array_intersect(self::assignable(), array_filter($capabilities, 'is_string')));

        foreach ($set as $capability) {
            $set = [...$set, ...(self::IMPLIES[$capability] ?? [])];
        }

        return array_values(array_intersect(self::assignable(), $set));
    }
}
