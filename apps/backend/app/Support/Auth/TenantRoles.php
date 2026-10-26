<?php

namespace App\Support\Auth;

/** Membership roles; what a member may do is their permission list (TenantPermissions, ADR 0079). */
class TenantRoles
{
    public const OWNER = 'owner';

    public const ADMIN = 'admin';

    public const OPERATOR = 'operator';

    public const VIEWER = 'viewer';
}
