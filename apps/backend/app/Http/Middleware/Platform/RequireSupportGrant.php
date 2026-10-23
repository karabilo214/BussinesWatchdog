<?php

namespace App\Http\Middleware\Platform;

use App\Models\PlatformStaff;
use App\Support\Platform\SupportGrants;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** Resolve the resource from the database before checking a grant; URL tenant/store claims are never trusted. */
class RequireSupportGrant
{
    private const TABLES = [
        'order' => 'orders', 'check' => 'check_runs', 'artifact' => 'artifacts', 'incident' => 'incidents',
    ];

    public function __construct(private readonly SupportGrants $grants) {}

    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $tenantId = $request->route('tenant');
        abort_unless(is_string($tenantId) && Str::isUuid($tenantId), 404);
        $storeId = $request->route('store');
        $type = 'store';
        $targetId = $storeId;

        if ($storeId === null) {
            foreach (self::TABLES as $parameter => $table) {
                $id = $request->route($parameter);

                if (! is_string($id)) {
                    continue;
                }

                abort_unless(Str::isUuid($id), 404);
                $storeId = DB::table($table)->where('tenant_id', $tenantId)->where('id', $id)->value('store_id');
                $type = $parameter;
                $targetId = $id;
                break;
            }
        }

        abort_unless(is_string($storeId) && Str::isUuid($storeId) && DB::table('stores')->where('tenant_id', $tenantId)->where('id', $storeId)->exists(), 404);
        /** @var PlatformStaff $staff */
        $staff = $request->attributes->get('platform_staff');
        $grant = $this->grants->require($staff, $tenantId, $storeId, $scope, $type, (string) $targetId);
        $request->attributes->set('support_grant_id', $grant->id);

        return $next($request);
    }
}
