<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\PlatformStaff;
use App\Support\Account\MfaService;
use App\Support\Platform\PlatformAudit;
use App\Support\Platform\StaffAdmin;
use App\Support\Platform\StaffRejected;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class StaffController extends Controller
{
    public function __construct(
        private readonly StaffAdmin $staff,
        private readonly MfaService $mfa,
        private readonly PlatformAudit $audit,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json($this->staff->catalog());
    }

    public function invite(Request $request): JsonResponse
    {
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $validated = $request->validate([
            'email' => ['required', 'email:rfc', 'max:254'],
            'role' => ['required', Rule::in(PlatformStaff::ROLES)],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'confirmation_code' => ['required', 'string', 'max:32'],
        ]);

        return $this->stepUp($request, 'staff.invited', $validated['confirmation_code'], fn (PlatformStaff $actor): JsonResponse => response()->json(
            $this->staff->invitation($this->staff->invite($actor, $validated['email'], $validated['role'], trim($validated['reason']))),
            201,
        ));
    }

    public function revokeInvitation(Request $request, string $invitation): Response|JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return $this->guard(function () use ($request, $invitation, $validated): Response {
            $this->staff->revokeInvitation($this->actor($request), $invitation, trim($validated['reason']));

            return response()->noContent();
        });
    }

    public function update(Request $request, string $staff): JsonResponse
    {
        $validated = $request->validate([
            'role' => ['sometimes', Rule::in(PlatformStaff::ROLES)],
            'state' => ['sometimes', Rule::in([PlatformStaff::STATE_ACTIVE, PlatformStaff::STATE_DISABLED])],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'confirmation_code' => ['required', 'string', 'max:32'],
        ]);

        return $this->stepUp($request, 'staff.updated', $validated['confirmation_code'], function (PlatformStaff $actor) use ($staff, $validated): JsonResponse {
            $this->staff->update($actor, $staff, array_intersect_key($validated, ['role' => true, 'state' => true]), trim($validated['reason']));

            return response()->json($this->staff->catalog());
        });
    }

    public function revokeSessions(Request $request, string $staff): Response|JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return $this->guard(function () use ($request, $staff, $validated): Response {
            $this->staff->revokeSessions($this->actor($request), $staff, trim($validated['reason']));

            return response()->noContent();
        });
    }

    /**
     * @param  Closure(PlatformStaff): JsonResponse  $action
     */
    private function stepUp(Request $request, string $auditAction, string $code, Closure $action): JsonResponse
    {
        $actor = $this->actor($request);

        if (! $this->mfa->verify($actor->user, $code)) {
            $this->audit->record($actor->id, $auditAction, 'staff', $actor->id, PlatformAudit::RESULT_DENIED, ['stage' => 'step_up']);

            return response()->json(['code' => 'step_up_failed', 'message' => 'The confirmation code is not valid.'], 403);
        }

        return $this->guard(fn (): JsonResponse => $action($actor));
    }

    /**
     * @param  Closure(): (JsonResponse|Response)  $action
     */
    private function guard(Closure $action): JsonResponse|Response
    {
        try {
            return $action();
        } catch (StaffRejected $exception) {
            return response()->json(['code' => $exception->reasonCode, 'message' => 'The staff change was rejected.'], $exception->status);
        }
    }

    private function actor(Request $request): PlatformStaff
    {
        /** @var PlatformStaff $staff */
        $staff = $request->attributes->get('platform_staff');

        return $staff;
    }
}
