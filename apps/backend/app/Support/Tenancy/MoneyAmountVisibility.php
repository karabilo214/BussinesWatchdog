<?php

namespace App\Support\Tenancy;

use App\Support\Auth\TenantPermissions;
use Illuminate\Http\Request;

/** Amounts are shown to the owner and to members with the `money.view` permission (ADR 0079). */
class MoneyAmountVisibility
{
    public function __construct(private readonly TenantAccess $access) {}

    public function allows(Request $request, string $tenantId): bool
    {
        return $this->access->allows($request, $tenantId, TenantPermissions::MONEY_VIEW);
    }
}
