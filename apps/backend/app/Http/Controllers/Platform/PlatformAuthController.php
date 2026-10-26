<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\PlatformStaff;
use App\Models\User;
use App\Support\Account\MfaService;
use App\Support\Billing\Paddle\PaddleConfig;
use App\Support\Platform\PlatformAudit;
use App\Support\Platform\PlatformCapabilities;
use App\Support\Platform\PlatformSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class PlatformAuthController extends Controller
{
    public const PENDING = 'platform_pending_login';

    public function __construct(
        private readonly MfaService $mfa,
        private readonly PlatformSession $session,
        private readonly PlatformAudit $audit,
    ) {}

    public function csrf(): Response
    {
        return response()->noContent();
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:254'],
            'password' => ['required', 'string', 'max:128'],
        ]);
        $credentials = ['email' => mb_strtolower(trim($validated['email'])), 'password' => $validated['password']];
        $guard = Auth::guard('platform');
        /** @var User|null $user */
        $user = $guard->validate($credentials) ? $guard->getProvider()->retrieveByCredentials($credentials) : null;
        $staff = $user === null ? null : PlatformStaff::query()->where('user_id', $user->id)->first();

        if ($staff === null || ! $staff->active()) {
            $this->audit->record($staff?->id, 'staff.login', 'staff_login', hash('sha256', $credentials['email']), PlatformAudit::RESULT_DENIED, ['stage' => 'password']);

            return response()->json(['code' => 'platform_login_failed', 'message' => 'Wrong email or password.'], 422);
        }

        if (! $this->mfa->enabled($user)) {
            $this->audit->record($staff->id, 'staff.login', 'staff', $staff->id, PlatformAudit::RESULT_DENIED, ['stage' => 'mfa_not_enrolled']);

            return response()->json(['code' => 'platform_mfa_enrollment_required', 'message' => 'Two-factor authentication must be set up with the server command first.'], 403);
        }

        $request->session()->regenerate();
        $request->session()->put(self::PENDING, [
            'staff_id' => $staff->id,
            'expires_at' => Carbon::now()->getTimestamp() + (int) config('watchdog.platform.mfa_pending_seconds'),
            'attempts' => 0,
        ]);

        return response()->json(['mfa_required' => true]);
    }

    public function mfa(Request $request): JsonResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'max:32']]);
        $pending = $request->session()->get(self::PENDING);

        if (! is_array($pending) || $pending['expires_at'] < Carbon::now()->getTimestamp() || $pending['attempts'] >= (int) config('watchdog.platform.mfa_max_attempts')) {
            $request->session()->forget(self::PENDING);

            return response()->json(['code' => 'platform_mfa_expired', 'message' => 'Sign in again.'], 401);
        }

        /** @var PlatformStaff|null $staff */
        $staff = PlatformStaff::query()->with('user')->whereKey($pending['staff_id'])->first();

        if ($staff === null || ! $staff->active()) {
            $request->session()->forget(self::PENDING);

            return response()->json(['code' => 'platform_mfa_expired', 'message' => 'Sign in again.'], 401);
        }

        if (! $this->mfa->verify($staff->user, $validated['code'])) {
            $pending['attempts']++;
            $request->session()->put(self::PENDING, $pending);
            $this->audit->record($staff->id, 'staff.login', 'staff', $staff->id, PlatformAudit::RESULT_DENIED, ['stage' => 'mfa']);

            return response()->json(['code' => 'mfa_code_invalid', 'message' => 'The code is not valid.'], 422);
        }

        $request->session()->forget(self::PENDING);
        $this->session->start($request, $staff);
        $this->audit->record($staff->id, 'staff.login', 'staff', $staff->id, PlatformAudit::RESULT_SUCCEEDED);

        return response()->json($this->me($staff));
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->me($request->attributes->get('platform_staff')));
    }

    public function logout(Request $request): Response
    {
        $staff = $request->attributes->get('platform_staff');
        $this->audit->record($staff->id, 'staff.logout', 'staff', $staff->id, PlatformAudit::RESULT_SUCCEEDED);
        $this->session->end($request);

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function me(PlatformStaff $staff): array
    {
        return [
            'staff_id' => $staff->id,
            'name' => $staff->user->name,
            'first_name' => $staff->user->first_name,
            'last_name' => $staff->user->last_name,
            'photo_url' => $staff->photoUrl(),
            'email' => $staff->user->email,
            'locale' => $staff->user->locale,
            'role' => $staff->role,
            'mfa_enabled' => true,
            'capabilities' => PlatformCapabilities::forStaff($staff),
            'billing_mode' => PaddleConfig::mode(),
            'session' => [
                'idle_minutes' => (int) config('watchdog.platform.idle_minutes'),
                'absolute_hours' => (int) config('watchdog.platform.absolute_hours'),
            ],
        ];
    }
}
