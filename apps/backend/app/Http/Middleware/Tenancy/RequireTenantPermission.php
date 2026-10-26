<?php

namespace App\Http\Middleware\Tenancy;

use App\Models\Membership;
use App\Support\Auth\TenantPermissions;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Checked on every request against the stored list, so a change applies at once, also in open sessions (ADR 0079). */
class RequireTenantPermission
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $tenantId = $this->tenantContext->requireTenantId('authorize tenant permission');
        $userId = $request->user()?->id;
        $membership = $userId === null ? null : Membership::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->first();

        abort_if($membership === null, 403);
        abort_unless(TenantPermissions::allows($membership, $permission), 403);

        return $next($request);
    }
}
