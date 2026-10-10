<?php

namespace App\Support\Account;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/** Re-authentication for sensitive account actions: the current password, plus a second-factor code when MFA is on. */
class StepUp
{
    public function __construct(
        private readonly MfaService $mfa,
    ) {}

    public function require(User $user, ?string $password, ?string $code, bool $codeWhenEnabled = true): void
    {
        if (! is_string($password) || ! Hash::check($password, $user->password_hash)) {
            throw ValidationException::withMessages(['current_password' => 'current_password_invalid']);
        }

        if ($codeWhenEnabled && $this->mfa->enabled($user) && ! $this->mfa->verify($user, $code)) {
            throw ValidationException::withMessages(['code' => ($code ?? '') === '' ? 'mfa_code_required' : 'mfa_code_invalid']);
        }
    }
}
