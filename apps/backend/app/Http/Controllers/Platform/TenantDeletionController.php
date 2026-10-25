<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\DataDeletionRequest;
use App\Models\PlatformStaff;
use App\Models\Tenant;
use App\Support\Account\MfaService;
use App\Support\Platform\PlatformAudit;
use App\Support\Platform\TenantDirectory;
use App\Support\Privacy\DeletionRejected;
use App\Support\Privacy\OrganizationDeletion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** The platform owner deletes an organization on its written request, or cancels that within the 30 days (ADR 0069). */
class TenantDeletionController extends Controller
{
    public function __construct(
        private readonly OrganizationDeletion $deletion,
        private readonly TenantDirectory $directory,
        private readonly MfaService $mfa,
        private readonly PlatformAudit $audit,
    ) {}

    public function store(Request $request, string $tenant): JsonResponse
    {
        return $this->act($request, $tenant, 'tenant.deletion_requested', fn (Tenant $model, PlatformStaff $staff, string $reason) => $this->deletion->request($model, $staff->user_id, DataDeletionRequest::VIA_PLATFORM, $reason));
    }

    public function cancel(Request $request, string $tenant): JsonResponse
    {
        return $this->act($request, $tenant, 'tenant.deletion_cancelled', fn (Tenant $model, PlatformStaff $staff) => $this->deletion->cancel($model, $staff->user_id));
    }

    private function act(Request $request, string $tenant, string $action, callable $apply): JsonResponse
    {
        $model = Str::isUuid($tenant) ? Tenant::query()->whereKey($tenant)->whereNot('status', 'deleted')->first() : null;

        if ($model === null) {
            return response()->json(['code' => 'not_found', 'message' => 'Not found.'], 404);
        }

        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500'], 'confirmation_code' => ['required', 'string', 'max:32']]);
        /** @var PlatformStaff $staff */
        $staff = $request->attributes->get('platform_staff');
        $reason = trim($validated['reason']);

        if (! $this->mfa->verify($staff->user, $validated['confirmation_code'])) {
            $this->audit->record($staff->id, $action, 'tenant', $model->id, PlatformAudit::RESULT_DENIED, ['stage' => 'step_up'], null, $model->id);

            return response()->json(['code' => 'step_up_failed', 'message' => 'The confirmation code is not valid.'], 403);
        }

        try {
            $deletion = $apply($model, $staff, $reason);
        } catch (DeletionRejected $exception) {
            $this->audit->record($staff->id, $action, 'tenant', $model->id, PlatformAudit::RESULT_FAILED, ['code' => $exception->reasonCode], $reason, $model->id);

            return response()->json(['code' => $exception->reasonCode, 'message' => 'The deletion request was refused.'], $exception->status);
        }

        $this->audit->record($staff->id, $action, 'tenant', $model->id, PlatformAudit::RESULT_SUCCEEDED, ['request_id' => $deletion->id, 'purge_after' => $deletion->purge_after->toJSON(), 'before' => ['status' => $model->status], 'after' => ['status' => $model->fresh()->status]], $reason, $model->id);

        return response()->json($this->directory->tenant($model->id, true, $staff->role === PlatformStaff::ROLE_OWNER));
    }
}
