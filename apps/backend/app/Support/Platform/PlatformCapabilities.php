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

    public const BILLING_READ = 'billing.read';

    public const CONTENT_MANAGE = 'content.manage';

    public const CONTACTS_READ = 'contacts.read';

    public const CONTACTS_MANAGE = 'contacts.manage';

    public const REFUNDS_REQUEST = 'refunds.request';

    public const REFUNDS_APPROVE = 'refunds.approve';

    private const ROLES = [
        PlatformStaff::ROLE_OWNER => [self::PLANS_MANAGE, self::PLANS_READ, self::STAFF_MANAGE, self::AUDIT_READ, self::TENANTS_READ, self::TENANTS_MANAGE, self::BILLING_READ, self::CONTENT_MANAGE, self::CONTACTS_READ, self::CONTACTS_MANAGE, self::REFUNDS_REQUEST, self::REFUNDS_APPROVE],
        PlatformStaff::ROLE_ADMIN => [self::PLANS_READ, self::TENANTS_READ, self::AUDIT_READ, self::CONTACTS_READ],
        PlatformStaff::ROLE_SUPPORT => [self::TENANTS_READ, self::CONTACTS_READ, self::CONTACTS_MANAGE],
        PlatformStaff::ROLE_BILLING => [self::PLANS_READ, self::TENANTS_READ, self::BILLING_READ, self::REFUNDS_REQUEST],
    ];

    /**
     * @return list<string>
     */
    public static function for(string $role): array
    {
        return self::ROLES[$role] ?? [];
    }
}
