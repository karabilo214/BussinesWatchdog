<?php

namespace App\Http\Middleware\Platform;

use App\Models\PlatformStaff;
use App\Models\User;
use App\Support\Account\MfaService;
use App\Support\Platform\PlatformCapabilities;
use App\Support\Platform\PlatformSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePlatformStaff
{
    public function __construct(
        private readonly MfaService $mfa,
        private readonly PlatformSession $session,
    ) {}

    public function handle(Request $request, Closure $next, string ...$capabilities): Response
    {
        /** @var User|null $user */
        $user = $request->user('platform');
        $staff = $user === null ? null : PlatformStaff::query()->where('user_id', $user->id)->first();

        if ($user === null || $staff === null || ! $staff->active() || ! $this->mfa->enabled($user)) {
            if ($user !== null) {
                $this->session->end($request);
            }

            return response()->json(['code' => 'platform_unauthenticated', 'message' => 'Sign in to the control panel.'], 401);
        }

        if ($this->session->expired($request, $staff)) {
            $this->session->end($request);

            return response()->json(['code' => 'platform_session_expired', 'message' => 'The session has expired. Sign in again.'], 401);
        }

        foreach ($capabilities as $capability) {
            if (! PlatformCapabilities::allows($staff, $capability)) {
                return response()->json(['code' => 'platform_forbidden', 'message' => 'This action is not allowed for your role.'], 403);
            }
        }

        $this->session->touch($request);
        $request->attributes->set('platform_staff', $staff);

        return $next($request);
    }
}
