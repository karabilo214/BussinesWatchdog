<?php

namespace App\Support\Account;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class PasswordResetService
{
    public const TTL_MINUTES = 60;

    public function __construct(
        private readonly AccountMailer $mailer,
    ) {}

    /** Mails a one-time link when the address belongs to an active user; the caller answers the same way either way. */
    public function request(string $email): void
    {
        /** @var User|null $user */
        $user = User::query()->where('email', $email)->whereNull('disabled_at')->first();

        if ($user === null) {
            return;
        }

        $this->sendTo($user, '/app/reset-password');
    }

    /** The same one-time link, opened in the given app (the customer cabinet or the owner panel). */
    public function sendTo(User $user, string $path): void
    {
        $token = Str::random(64);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            ['token' => hash('sha256', $token), 'created_at' => Carbon::now()],
        );

        $this->mailer->send($user->email, $user->locale, 'password_reset', [
            'link' => $this->mailer->link($path.'?'.http_build_query(['token' => $token, 'email' => $user->email])),
            'minutes' => self::TTL_MINUTES,
        ]);
    }

    /** One-time: the token is consumed, every session of the user ends, and the address counts as confirmed. */
    public function complete(string $email, string $token, string $password): void
    {
        DB::transaction(function () use ($email, $token, $password): void {
            $row = DB::table('password_reset_tokens')->where('email', $email)->lockForUpdate()->first();
            /** @var User|null $user */
            $user = User::query()->where('email', $email)->whereNull('disabled_at')->lockForUpdate()->first();

            $valid = $row !== null
                && $user !== null
                && hash_equals((string) $row->token, hash('sha256', $token))
                && Carbon::parse($row->created_at)->addMinutes(self::TTL_MINUTES)->isFuture();

            if (! $valid) {
                throw new AccountRejected('password_reset_invalid');
            }

            DB::table('password_reset_tokens')->where('email', $email)->delete();

            $user->forceFill([
                'password_hash' => Hash::make($password),
                'remember_token' => Str::random(60),
                'email_verified_at' => $user->email_verified_at ?? Carbon::now(),
            ])->save();

            DB::table('sessions')->where('user_id', $user->id)->delete();
            DB::table('trusted_devices')->where('user_id', $user->id)->delete();
        });
    }
}
