<?php

namespace App\Support\Platform;

use App\Models\PlatformStaff;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class PlatformSession
{
    public const LOGIN_AT = 'platform_login_at';

    public const LAST_SEEN_AT = 'platform_last_seen_at';

    public const PERMISSION_VERSION = 'platform_permission_version';

    public function start(Request $request, PlatformStaff $staff): void
    {
        Auth::guard('platform')->login($staff->user);
        $request->session()->regenerate();
        $now = Carbon::now()->getTimestamp();
        $request->session()->put(self::LOGIN_AT, $now);
        $request->session()->put(self::LAST_SEEN_AT, $now);
        $request->session()->put(self::PERMISSION_VERSION, $staff->permission_version);
    }

    public function expired(Request $request, PlatformStaff $staff): bool
    {
        $now = Carbon::now()->getTimestamp();
        $loginAt = (int) $request->session()->get(self::LOGIN_AT, 0);
        $lastSeen = (int) $request->session()->get(self::LAST_SEEN_AT, 0);

        return $loginAt === 0
            || $now - $loginAt > 3600 * (int) config('watchdog.platform.absolute_hours')
            || $now - $lastSeen > 60 * (int) config('watchdog.platform.idle_minutes')
            || (int) $request->session()->get(self::PERMISSION_VERSION) !== $staff->permission_version;
    }

    public function touch(Request $request): void
    {
        $request->session()->put(self::LAST_SEEN_AT, Carbon::now()->getTimestamp());
    }

    public function end(Request $request): void
    {
        Auth::guard('platform')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }
}
