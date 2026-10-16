<?php

namespace App\Http\Controllers\Api\V1\Tenancy;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Support\Tenancy\MoneyAmountVisibility;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MoneyVisibilityController extends Controller
{
    public function show(Request $request, TenantContext $context, MoneyAmountVisibility $visibility): JsonResponse
    {
        $tenantId = $context->requireTenantId('read money visibility');
        $tenant = Tenant::query()->findOrFail($tenantId);

        return response()->json([
            'show_money_amounts_to_team' => $tenant->show_money_amounts_to_team,
            'can_view_money_amounts' => $visibility->allows($request, $tenantId),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, TenantContext $context): JsonResponse
    {
        $validated = $request->validate(['show_money_amounts_to_team' => ['required', 'boolean']]);
        $tenantId = $context->requireTenantId('update money visibility');
        $enabled = (bool) $validated['show_money_amounts_to_team'];

        DB::transaction(function () use ($request, $tenantId, $enabled): void {
            $tenant = Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
            $old = $tenant->show_money_amounts_to_team;

            if ($old === $enabled) {
                return;
            }

            $tenant->show_money_amounts_to_team = $enabled;
            $tenant->config_version++;
            $tenant->save();

            AuditLog::query()->create([
                'tenant_id' => $tenantId,
                'actor_user_id' => $request->user()->id,
                'actor_type' => AuditLog::ACTOR_USER,
                'action' => 'tenant.money_visibility_updated',
                'entity_type' => 'tenant',
                'entity_id' => $tenantId,
                'changes' => ['before' => $old, 'after' => $enabled],
                'request_id' => (string) Str::uuid(),
                'created_at' => now(),
            ]);
        });

        return response()->json(['show_money_amounts_to_team' => $enabled, 'can_view_money_amounts' => true])
            ->header('Cache-Control', 'private, no-store');
    }
}
