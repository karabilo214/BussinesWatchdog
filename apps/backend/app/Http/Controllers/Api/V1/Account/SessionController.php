<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Membership;
use App\Models\User;
use App\Support\Account\StepUp;
use App\Support\Account\TrustedDevices;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SessionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $currentId = $request->session()->getId();
        $cutoff = Carbon::now()->subMinutes((int) config('session.lifetime'))->getTimestamp();
        $sessions = DB::table('sessions')
            ->where('user_id', $user->id)
            ->where('last_activity', '>=', $cutoff)
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity']);

        $items = $sessions->map(fn ($session): array => [
            'current' => $session->id === $currentId,
            'device' => $this->device($session->user_agent),
            'ip_address' => $session->ip_address,
            'last_active_at' => Carbon::createFromTimestamp($session->last_activity)->toJSON(),
        ])->all();

        // A newly created session is written to the database after this response.
        if (! $sessions->contains('id', $currentId)) {
            array_unshift($items, [
                'current' => true,
                'device' => $this->device($request->userAgent()),
                'ip_address' => $request->ip(),
                'last_active_at' => Carbon::now()->toJSON(),
            ]);
        }

        return response()->json(['data' => $items]);
    }

    public function destroyAll(Request $request, StepUp $stepUp, TrustedDevices $trustedDevices): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string', 'max:128'],
            'code' => ['nullable', 'string', 'max:32'],
        ]);
        /** @var User $user */
        $user = $request->user();
        $stepUp->require($user, $validated['current_password'], $validated['code'] ?? null);

        DB::table('sessions')->where('user_id', $user->id)->delete();
        $trustedDevices->revokeAll($user);
        $trustedDevices->clearCookie();

        $requestId = (string) Str::uuid();
        foreach (Membership::query()->where('user_id', $user->id)->pluck('tenant_id') as $tenantId) {
            AuditLog::query()->create([
                'tenant_id' => $tenantId,
                'store_id' => null,
                'actor_user_id' => $user->id,
                'actor_type' => AuditLog::ACTOR_USER,
                'action' => 'user.sessions_revoked',
                'entity_type' => 'user',
                'entity_id' => $user->id,
                'changes' => [],
                'request_id' => $requestId,
                'created_at' => Carbon::now(),
            ]);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(null, 204);
    }

    private function device(?string $agent): string
    {
        if ($agent === null || $agent === '') {
            return 'unknown';
        }

        foreach (['Edg/' => 'Edge', 'Firefox/' => 'Firefox', 'Chrome/' => 'Chrome', 'Safari/' => 'Safari'] as $marker => $name) {
            if (str_contains($agent, $marker)) {
                return $name;
            }
        }

        return 'other';
    }
}
