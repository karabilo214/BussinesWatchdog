<?php

namespace App\Http\Middleware\Platform;

use App\Models\AuditLog;
use App\Models\PlatformStaff;
use App\Support\Platform\PlatformAudit;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every change made from the owner panel is audited (ADR 0048): a successful POST/PUT/PATCH/DELETE that did not write
 * its own platform audit entry gets a `platform.change` entry, and a change of a customer organisation that did not
 * write to the organisation's audit gets one there (`by: platform`). Sign-in and previews change nothing and are left
 * out; views are not audited.
 */
class RecordPlatformChanges
{
    public const AUDITED = 'platform_audited';

    public const TENANTS_AUDITED = 'platform_tenants_audited';

    private const METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! in_array($request->getMethod(), self::METHODS, true) || ! $response->isSuccessful() || $this->excluded($request)) {
            return $response;
        }

        $staff = $request->attributes->get('platform_staff');

        if (! $staff instanceof PlatformStaff) {
            return $response;
        }

        $route = (string) ($request->route()?->uri() ?? $request->path());
        $tenantId = $request->route('tenant');
        $tenantId = is_string($tenantId) && Str::isUuid($tenantId) ? $tenantId : null;
        $changes = ['method' => $request->getMethod(), 'route' => $route, 'status' => $response->getStatusCode()];

        if ($request->attributes->get(self::AUDITED) !== true) {
            app(PlatformAudit::class)->record($staff->id, 'platform.change', 'route', $route, PlatformAudit::RESULT_SUCCEEDED, $changes, null, $tenantId);
        }

        if ($tenantId !== null && ! in_array($tenantId, (array) $request->attributes->get(self::TENANTS_AUDITED, []), true)) {
            AuditLog::query()->create([
                'tenant_id' => $tenantId,
                'store_id' => null,
                'actor_user_id' => null,
                'actor_type' => AuditLog::ACTOR_SYSTEM,
                'action' => 'platform.change',
                'entity_type' => 'route',
                'entity_id' => (string) Str::uuid(),
                'changes' => $changes + ['by' => 'platform'],
                'request_id' => (string) ($request->attributes->get('request_id') ?? Str::uuid()),
                'created_at' => Carbon::now(),
            ]);
        }

        return $response;
    }

    private function excluded(Request $request): bool
    {
        $path = $request->path();

        return str_starts_with($path, 'platform-api/v1/auth/')
            || str_starts_with($path, 'platform-api/v1/staff-invitations/')
            || str_ends_with($path, '/preview')
            || str_contains($path, '-preview');
    }
}
