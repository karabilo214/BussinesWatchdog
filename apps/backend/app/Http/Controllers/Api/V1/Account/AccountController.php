<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Http\Controllers\Controller;
use App\Http\Dto\Auth\AuthSessionDto;
use App\Models\User;
use App\Support\Account\AccountRejected;
use App\Support\Account\EmailVerificationService;
use App\Support\Account\PasswordResetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AccountController extends Controller
{
    public function __construct(
        private readonly PasswordResetService $passwordResets,
        private readonly EmailVerificationService $emailVerification,
        private readonly AuthSessionDto $authSessionDto,
    ) {}

    public function requestPasswordReset(Request $request): JsonResponse
    {
        $validated = $request->validate(['email' => ['required', 'string', 'max:255']]);

        $this->passwordResets->request(mb_strtolower(trim($validated['email'])));

        return response()->json(['status' => 'requested'], 202);
    }

    public function completePasswordReset(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'max:255'],
            'token' => ['required', 'string', 'max:128'],
            'password' => ['required', 'confirmed', 'max:128', Password::min(12)],
        ]);

        try {
            $this->passwordResets->complete(mb_strtolower(trim($validated['email'])), $validated['token'], $validated['password']);
        } catch (AccountRejected $exception) {
            return $this->rejected($exception);
        }

        return response()->json(null, 204);
    }

    public function resendVerification(Request $request): JsonResponse
    {
        try {
            $this->emailVerification->send($request->user());
        } catch (AccountRejected $exception) {
            return $this->rejected($exception);
        }

        return response()->json(['status' => 'sent'], 202);
    }

    /** Signed, expiring link from the email; works without a session and lands on the dashboard. */
    public function verifyEmail(Request $request, string $user, string $hash): RedirectResponse
    {
        /** @var User|null $account */
        $account = Str::isUuid($user) ? User::query()->find($user) : null;
        $valid = $request->hasValidRelativeSignature() && $account !== null && $this->emailVerification->confirm($account, $hash);

        return redirect('/app/overview?'.http_build_query(['email_verified' => $valid ? '1' : '0']));
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'min:1', 'max:100'],
            'locale' => ['sometimes', 'string', 'in:ru,en,de'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $user->forceFill($validated)->save();

        return response()->json($this->authSessionDto->toArray($user->refresh(), $request->session()->get('active_tenant_id')));
    }

    /** Ends every other session of the user; the current one stays signed in with a fresh id. */
    public function changePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string', 'max:128'],
            'password' => ['required', 'confirmed', 'max:128', Password::min(12)],
        ]);

        /** @var User $user */
        $user = $request->user();

        if (! Hash::check($validated['current_password'], $user->password_hash)) {
            throw ValidationException::withMessages(['current_password' => 'current_password_invalid']);
        }

        $user->forceFill(['password_hash' => Hash::make($validated['password']), 'remember_token' => Str::random(60)])->save();
        $request->session()->regenerate();
        DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', $request->session()->getId())->delete();

        return response()->json(null, 204);
    }

    private function rejected(AccountRejected $exception): JsonResponse
    {
        return response()->json(['code' => $exception->reasonCode, 'message' => 'The request was rejected.'], $exception->status);
    }
}
