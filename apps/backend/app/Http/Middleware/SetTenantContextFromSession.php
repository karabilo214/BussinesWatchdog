<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetTenantContextFromSession
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = $request->session()->get('active_tenant_id');

        if ($tenantId !== null && $this->userCanAccessTenant($request->user(), (string) $tenantId)) {
            $this->tenantContext->set((string) $tenantId, 'session');
        } else {
            $this->tenantContext->clear();
        }

        try {
            return $next($request);
        } finally {
            $this->tenantContext->clear();
        }
    }

    private function userCanAccessTenant(?User $user, string $tenantId): bool
    {
        if ($user === null) {
            return false;
        }

        return $user->memberships()
            ->where('tenant_id', $tenantId)
            ->exists();
    }
}
