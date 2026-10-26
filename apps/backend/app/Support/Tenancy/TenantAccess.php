<?php

namespace App\Support\Tenancy;

use App\Models\Membership;
use App\Support\Auth\TenantPermissions;
use Illuminate\Http\Request;

/** The signed-in member's permissions in a tenant, read from the stored list on every call (ADR 0079). */
class TenantAccess
{
    public function allows(Request $request, string $tenantId, string $permission): bool
    {
        return TenantPermissions::allows($this->membership($request, $tenantId), $permission);
    }

    /**
     * @return list<string>
     */
    public function permissions(Request $request, string $tenantId): array
    {
        return TenantPermissions::forMembership($this->membership($request, $tenantId));
    }

    private function membership(Request $request, string $tenantId): ?Membership
    {
        $userId = $request->user()?->id;

        return $userId === null ? null : Membership::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->first();
    }
}
