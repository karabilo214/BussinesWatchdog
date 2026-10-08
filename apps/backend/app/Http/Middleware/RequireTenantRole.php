<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireTenantRole
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {
    }

    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $tenantId = $this->tenantContext->requireTenantId('authorize tenant role');
        $role = $this->membershipRole($request->user(), $tenantId);

        abort_if($role === null, 403);
        abort_unless(in_array($role, $roles, true), 403);

        return $next($request);
    }

    private function membershipRole(?User $user, string $tenantId): ?string
    {
        if ($user === null) {
            return null;
        }

        return $user->memberships()
            ->where('tenant_id', $tenantId)
            ->value('role');
    }
}
