<?php

namespace App\Http\Controllers\Api\V1\Tenancy;

use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Models\Membership;
use App\Models\NotificationChannel;
use App\Models\Tenant;
use App\Support\Auth\TenantPermissions;
use App\Support\Auth\TenantRoles;
use App\Support\Tenancy\TenantAccess;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Read-only facts about the organisation for the "My organisation" block; editing stays in each section. */
class OrganizationController extends Controller
{
    public function show(Request $request, TenantContext $context, TenantAccess $access): JsonResponse
    {
        $tenant = Tenant::query()->findOrFail($context->requireTenantId('read organization summary'));
        $owner = Membership::query()->where('tenant_id', $tenant->id)->where('role', TenantRoles::OWNER)->with('user:id,name')->first();

        return response()->json([
            'name' => $tenant->name,
            'created_at' => $tenant->created_at?->toJSON(),
            'locale' => $tenant->locale,
            'timezone' => $tenant->timezone,
            'owner_name' => $owner?->user?->name,
            'payment_providers' => $this->paymentProviders($tenant->id),
            'notification_channels' => $access->allows($request, $tenant->id, TenantPermissions::NOTIFICATIONS_MANAGE) ? $this->channels($tenant->id) : null,
        ])->header('Cache-Control', 'private, no-store');
    }

    /**
     * Independent payment provider connections per provider: in how many stores, and how many need attention.
     *
     * @return list<array{provider: string, stores: int, attention: int}>
     */
    private function paymentProviders(string $tenantId): array
    {
        return DB::table('integrations')
            ->join('stores', 'stores.id', '=', 'integrations.store_id')
            ->where('integrations.tenant_id', $tenantId)
            ->where('integrations.source_authority', Integration::SOURCE_INDEPENDENT_PROVIDER)
            ->whereIn('integrations.status', [Integration::STATUS_ACTIVE, Integration::STATUS_DEGRADED, Integration::STATUS_PENDING])
            ->whereNotIn('stores.status', ['deleted', 'deleting'])
            ->selectRaw("integrations.provider, COUNT(DISTINCT integrations.store_id) AS stores, SUM(CASE WHEN integrations.status = 'active' THEN 0 ELSE 1 END) AS attention")
            ->groupBy('integrations.provider')
            ->orderBy('integrations.provider')
            ->get()
            ->map(fn ($row): array => ['provider' => (string) $row->provider, 'stores' => (int) $row->stores, 'attention' => (int) $row->attention])
            ->all();
    }

    /**
     * @return array{enabled: int, unverified: int, failing: int}
     */
    private function channels(string $tenantId): array
    {
        $enabled = NotificationChannel::query()->where('tenant_id', $tenantId)->where('enabled', true)->get(['verified_at', 'health']);

        return [
            'enabled' => $enabled->count(),
            'unverified' => $enabled->whereNull('verified_at')->count(),
            'failing' => $enabled->filter(fn (NotificationChannel $channel): bool => $channel->verified_at !== null && ($channel->health['status'] ?? null) === 'failing')->count(),
        ];
    }
}
