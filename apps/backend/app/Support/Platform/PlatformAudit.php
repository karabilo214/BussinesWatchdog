<?php

namespace App\Support\Platform;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PlatformAudit
{
    public const RESULT_SUCCEEDED = 'succeeded';

    public const RESULT_DENIED = 'denied';

    public const RESULT_FAILED = 'failed';

    /**
     * @param  array<string, mixed>  $changes
     */
    public function record(?string $staffId, string $action, string $targetType, string $targetId, string $result, array $changes = [], ?string $reason = null, ?string $targetTenantId = null): void
    {
        $requestId = request()?->attributes->get('request_id');

        DB::table('platform_audit_log')->insert([
            'id' => (string) Str::uuid7(),
            'actor_staff_id' => $staffId,
            'actor_kind' => $staffId === null ? 'system' : 'staff',
            'action' => $action,
            'target_tenant_id' => $targetTenantId,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'reason' => $reason,
            'request_id' => is_string($requestId) && Str::isUuid($requestId) ? $requestId : (string) Str::uuid(),
            'sanitized_changes' => json_encode($changes, JSON_THROW_ON_ERROR),
            'result' => $result,
            'created_at' => Carbon::now(),
        ]);
    }
}
