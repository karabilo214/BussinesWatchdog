<?php

namespace App\Support\Account;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

class EmailVerificationService
{
    public const TTL_HOURS = 24;

    public function __construct(
        private readonly AccountMailer $mailer,
    ) {}

    public function send(User $user): void
    {
        if ($user->email_verified_at !== null) {
            throw new AccountRejected('email_already_verified', 409);
        }

        $path = URL::temporarySignedRoute('auth.email-verification.verify', Carbon::now()->addHours(self::TTL_HOURS), [
            'user' => $user->id,
            'hash' => $this->hash($user),
        ], absolute: false);

        $this->mailer->send($user->email, $user->locale, 'email_verification', [
            'link' => $this->mailer->link($path),
            'hours' => self::TTL_HOURS,
        ]);
    }

    /** The hash binds the link to the address it was sent to: changing the address makes old links useless. */
    public function confirm(User $user, string $hash): bool
    {
        if (! hash_equals($this->hash($user), $hash)) {
            return false;
        }

        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => Carbon::now()])->save();
        }

        return true;
    }

    private function hash(User $user): string
    {
        return hash_hmac('sha256', 'email-verification:'.$user->id.':'.$user->email, (string) config('app.key'));
    }
}
