<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Http\Controllers\Controller;
use App\Http\Dto\Auth\AuthSessionDto;
use App\Models\User;
use App\Support\Account\AccountRejected;
use App\Support\Account\MfaService;
use App\Support\Account\StepUp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class MfaController extends Controller
{
    /** Session key of a sign-in that passed the password and waits for the second factor. */
    public const PENDING_LOGIN = 'mfa_pending_login';

    public const PENDING_TTL_SECONDS = 300;

    public const PENDING_MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly MfaService $mfa,
        private readonly StepUp $stepUp,
        private readonly AuthSessionDto $authSessionDto,
    ) {}

    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json($this->state($user));
    }

    public function setup(Request $request): JsonResponse
    {
        $validated = $request->validate(['current_password' => ['required', 'string', 'max:128']]);
        /** @var User $user */
        $user = $request->user();
        $this->stepUp->require($user, $validated['current_password'], null, codeWhenEnabled: false);

        try {
            return response()->json($this->mfa->beginSetup($user));
        } catch (AccountRejected $exception) {
            return $this->rejected($exception);
        }
    }

    /** Enabling ends every other session: they were signed in without the second factor. */
    public function confirm(Request $request): JsonResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'max:32']]);
        /** @var User $user */
        $user = $request->user();

        try {
            $codes = $this->mfa->confirm($user, $validated['code']);
        } catch (AccountRejected $exception) {
            return $this->rejected($exception);
        }

        $request->session()->regenerate();
        DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', $request->session()->getId())->delete();

        return response()->json([...$this->state($user), 'recovery_codes' => $codes]);
    }

    public function disable(Request $request): JsonResponse
    {
        $validated = $request->validate(['current_password' => ['required', 'string', 'max:128'], 'code' => ['nullable', 'string', 'max:32']]);
        /** @var User $user */
        $user = $request->user();

        if (! $this->mfa->enabled($user)) {
            return $this->rejected(new AccountRejected('mfa_not_enabled', 409));
        }

        $this->stepUp->require($user, $validated['current_password'], $validated['code'] ?? null);
        $this->mfa->disable($user);

        return response()->json($this->state($user->refresh()));
    }

    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $validated = $request->validate(['current_password' => ['required', 'string', 'max:128'], 'code' => ['nullable', 'string', 'max:32']]);
        /** @var User $user */
        $user = $request->user();

        if (! $this->mfa->enabled($user)) {
            return $this->rejected(new AccountRejected('mfa_not_enabled', 409));
        }

        $this->stepUp->require($user, $validated['current_password'], $validated['code'] ?? null);

        return response()->json([...$this->state($user), 'recovery_codes' => $this->mfa->regenerateRecoveryCodes($user)]);
    }

    /** Second step of sign-in: an authenticator or recovery code for the sign-in waiting in this session. */
    public function challenge(Request $request): JsonResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'max:32']]);
        $pending = $request->session()->get(self::PENDING_LOGIN);

        if (! is_array($pending) || ($pending['expires_at'] ?? 0) < Carbon::now()->getTimestamp() || ($pending['attempts'] ?? 0) >= self::PENDING_MAX_ATTEMPTS) {
            $request->session()->forget(self::PENDING_LOGIN);

            return $this->rejected(new AccountRejected('mfa_challenge_expired', 401));
        }

        /** @var User|null $user */
        $user = User::query()->whereKey($pending['user_id'] ?? null)->whereNull('disabled_at')->first();

        if ($user === null || ! $this->mfa->verify($user, $validated['code'])) {
            $request->session()->put(self::PENDING_LOGIN, [...$pending, 'attempts' => ($pending['attempts'] ?? 0) + 1]);

            return $this->rejected(new AccountRejected('mfa_code_invalid'));
        }

        $request->session()->forget(self::PENDING_LOGIN);
        Auth::guard('web')->login($user, (bool) ($pending['remember'] ?? false));
        $request->session()->regenerate();
        $activeTenantId = $user->memberships()->value('tenant_id');

        if ($activeTenantId !== null) {
            $request->session()->put('active_tenant_id', $activeTenantId);
        }

        return response()->json($this->authSessionDto->toArray($user, $activeTenantId));
    }

    /**
     * @return array{enabled: bool, recovery_codes_remaining: int}
     */
    private function state(User $user): array
    {
        $enabled = $this->mfa->enabled($user);

        return ['enabled' => $enabled, 'recovery_codes_remaining' => $enabled ? $this->mfa->remainingRecoveryCodes($user) : 0];
    }

    private function rejected(AccountRejected $exception): JsonResponse
    {
        return response()->json(['code' => $exception->reasonCode, 'message' => 'The request was rejected.'], $exception->status);
    }
}
