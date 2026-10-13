<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\PlatformStaff;
use App\Support\Account\MfaService;
use App\Support\Platform\PlatformAudit;
use App\Support\Platform\PlatformSettings;
use App\Support\Platform\StaffRejected;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class SettingsController extends Controller
{
    public function __construct(
        private readonly PlatformSettings $settings,
        private readonly MfaService $mfa,
        private readonly PlatformAudit $audit,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json($this->settings->overview())->header('Cache-Control', 'no-store');
    }

    public function scheduleMaintenance(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date'],
            'messages' => ['required', 'array:'.implode(',', PlatformSettings::LOCALES)],
            'messages.*' => ['required', 'string', 'max:300'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'confirmation_code' => ['required', 'string', 'max:32'],
        ]);

        return $this->stepUp($request, 'settings.maintenance_scheduled', $validated['confirmation_code'], function (PlatformStaff $actor) use ($validated): JsonResponse {
            $this->settings->schedule($actor, Carbon::parse($validated['starts_at'])->utc(), Carbon::parse($validated['ends_at'])->utc(), $validated['messages'], trim($validated['reason']));

            return response()->json($this->settings->overview(), 201);
        });
    }

    public function cancelMaintenance(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'confirmation_code' => ['required', 'string', 'max:32'],
        ]);

        return $this->stepUp($request, 'settings.maintenance_cancelled', $validated['confirmation_code'], function (PlatformStaff $actor) use ($validated): JsonResponse {
            $this->settings->cancel($actor, trim($validated['reason']));

            return response()->json($this->settings->overview());
        });
    }

    /**
     * @param  Closure(PlatformStaff): JsonResponse  $action
     */
    private function stepUp(Request $request, string $auditAction, string $code, Closure $action): JsonResponse
    {
        /** @var PlatformStaff $actor */
        $actor = $request->attributes->get('platform_staff');

        if (! $this->mfa->verify($actor->user, $code)) {
            $this->audit->record($actor->id, $auditAction, 'maintenance_notice', 'step_up', PlatformAudit::RESULT_DENIED, ['stage' => 'step_up']);

            return response()->json(['code' => 'step_up_failed', 'message' => 'The confirmation code is not valid.'], 403);
        }

        try {
            return $action($actor);
        } catch (StaffRejected $exception) {
            return response()->json(['code' => $exception->reasonCode, 'message' => 'The settings change was rejected.'], $exception->status);
        }
    }
}
