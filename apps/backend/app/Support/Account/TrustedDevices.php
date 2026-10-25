<?php

namespace App\Support\Account;

use App\Models\AuditLog;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TrustedDevices
{
    public const COOKIE = 'bw_trusted_device';

    private const COOKIE_PATH = '/api/v1/auth';

    private const LIFETIME_DAYS = 30;

    public function recognize(Request $request, User $user): bool
    {
        $token = $request->cookie(self::COOKIE);
        if (! is_string($token) || ! preg_match('/^[a-f0-9]{64}$/D', $token)) {
            return false;
        }

        $updated = DB::table('trusted_devices')
            ->where('user_id', $user->id)
            ->where('token_hash', hash('sha256', $token))
            ->where('expires_at', '>', Carbon::now())
            ->update(['last_used_at' => Carbon::now(), 'updated_at' => Carbon::now()]);

        if ($updated !== 1) {
            return false;
        }

        $this->audit($user, 'user.trusted_device_login');

        return true;
    }

    public function issue(Request $request, User $user): void
    {
        $existing = $request->cookie(self::COOKIE);
        if (is_string($existing) && preg_match('/^[a-f0-9]{64}$/D', $existing)) {
            DB::table('trusted_devices')->where('user_id', $user->id)->where('token_hash', hash('sha256', $existing))->delete();
        }

        $token = bin2hex(random_bytes(32));
        $now = Carbon::now();
        $expiry = $now->copy()->addDays(self::LIFETIME_DAYS);

        DB::table('trusted_devices')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'device' => $this->device($request->userAgent()),
            'last_used_at' => $now,
            'expires_at' => $expiry,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Cookie::queue(Cookie::make(self::COOKIE, $token, self::LIFETIME_DAYS * 24 * 60, self::COOKIE_PATH, null,
            config('app.env') === 'production' || (bool) config('session.secure'), true, false, 'strict'));
        $this->audit($user, 'user.trusted_device_added');
    }

    /** @return array<int, array{id: string, device: string, current: bool, last_used_at: ?string, expires_at: string}> */
    public function listing(Request $request, User $user): array
    {
        $token = $request->cookie(self::COOKIE);
        $currentHash = is_string($token) && preg_match('/^[a-f0-9]{64}$/D', $token) ? hash('sha256', $token) : null;

        return DB::table('trusted_devices')->where('user_id', $user->id)->where('expires_at', '>', Carbon::now())
            ->orderByDesc('last_used_at')->get(['id', 'device', 'token_hash', 'last_used_at', 'expires_at'])
            ->map(fn ($row): array => [
                'id' => $row->id,
                'device' => $row->device,
                'current' => $currentHash !== null && hash_equals($row->token_hash, $currentHash),
                'last_used_at' => $row->last_used_at === null ? null : Carbon::parse($row->last_used_at)->toJSON(),
                'expires_at' => Carbon::parse($row->expires_at)->toJSON(),
            ])->all();
    }

    public function revoke(Request $request, User $user, string $id): bool
    {
        if (! Str::isUuid($id)) {
            return false;
        }

        $row = DB::table('trusted_devices')->where('user_id', $user->id)->where('id', $id)->first(['token_hash']);
        if ($row === null) {
            return false;
        }

        DB::table('trusted_devices')->where('id', $id)->delete();
        $token = $request->cookie(self::COOKIE);
        if (is_string($token) && hash_equals($row->token_hash, hash('sha256', $token))) {
            $this->clearCookie();
        }
        $this->audit($user, 'user.trusted_device_revoked', ['trusted_device_id' => $id]);

        return true;
    }

    public function revokeAll(User $user): void
    {
        DB::table('trusted_devices')->where('user_id', $user->id)->delete();
    }

    public function clearCookie(): void
    {
        Cookie::queue(Cookie::forget(self::COOKIE, self::COOKIE_PATH));
    }

    private function device(?string $agent): string
    {
        foreach (['Edg/' => 'Edge', 'Firefox/' => 'Firefox', 'Chrome/' => 'Chrome', 'Safari/' => 'Safari'] as $marker => $name) {
            if (is_string($agent) && str_contains($agent, $marker)) {
                return $name;
            }
        }

        return 'other';
    }

    /** @param array<string, mixed> $changes */
    private function audit(User $user, string $action, array $changes = []): void
    {
        $requestId = (string) Str::uuid();
        foreach (Membership::query()->where('user_id', $user->id)->pluck('tenant_id') as $tenantId) {
            AuditLog::query()->create([
                'tenant_id' => $tenantId,
                'store_id' => null,
                'actor_user_id' => $user->id,
                'actor_type' => AuditLog::ACTOR_USER,
                'action' => $action,
                'entity_type' => 'user',
                'entity_id' => $user->id,
                'changes' => $changes,
                'request_id' => $requestId,
                'created_at' => Carbon::now(),
            ]);
        }
    }
}
