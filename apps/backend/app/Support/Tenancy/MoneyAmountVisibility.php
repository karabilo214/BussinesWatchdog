<?php

namespace App\Support\Tenancy;

use App\Models\Membership;
use App\Models\Tenant;
use App\Support\Auth\TenantRoles;
use Illuminate\Http\Request;

class MoneyAmountVisibility
{
    public function allows(Request $request, string $tenantId): bool
    {
        $userId = $request->user()?->id;

        if ($userId === null) {
            return false;
        }

        $role = Membership::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->value('role');

        if ($role === TenantRoles::OWNER) {
            return true;
        }

        if ($role === null) {
            return false;
        }

        return (bool) Tenant::query()->whereKey($tenantId)->value('show_money_amounts_to_team');
    }
}
