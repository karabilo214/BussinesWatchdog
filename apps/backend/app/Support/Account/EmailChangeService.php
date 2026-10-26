<?php

namespace App\Support\Account;

use App\Models\AuditLog;
use App\Models\EmailChangeRequest;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A user changes only their own sign-in address (Step 267). The old address is warned at once and can cancel; the new
 * one gets a one-time link, and nothing changes until it is opened. Whether another account already uses the new
 * address is never shown on screen — that address only gets a letter saying so. Paddle and notification channels
 * keep their own addresses.
 */
class EmailChangeService
{
    public const TTL_HOURS = 24;

    public function __construct(private readonly AccountMailer $mailer, private readonly TrustedDevices $trustedDevices) {}

    public function pending(User $user): ?EmailChangeRequest
    {
        $request = EmailChangeRequest::query()->where('user_id', $user->id)->whereNull('confirmed_at')->whereNull('cancelled_at')->latest('created_at')->first();

        return $request?->open() ? $request : null;
    }

    public function request(User $user, string $email): EmailChangeRequest
    {
        if ($email === mb_strtolower($user->email)) {
            throw new AccountRejected('email_unchanged');
        }

        $confirmToken = Str::random(48);
        $cancelToken = Str::random(48);
        $taken = $this->taken($email, $user);

        $request = DB::transaction(function () use ($user, $email, $taken, $confirmToken, $cancelToken): EmailChangeRequest {
            EmailChangeRequest::query()->where('user_id', $user->id)->whereNull('confirmed_at')->whereNull('cancelled_at')->update(['cancelled_at' => Carbon::now()]);

            $request = EmailChangeRequest::query()->create([
                'user_id' => $user->id,
                'new_email' => $email,
                'confirm_token_hash' => $taken ? null : hash('sha256', $confirmToken),
                'cancel_token_hash' => hash('sha256', $cancelToken),
                'expires_at' => Carbon::now()->addHours(self::TTL_HOURS),
            ]);
            $this->audit($user, 'user.email_change_requested', ['to' => self::mask($email)]);

            return $request;
        });

        $this->mailer->send($user->email, $user->locale, 'email_change_requested', [
            'email' => self::mask($email),
            'link' => $this->mailer->link('/api/v1/auth/email-change/'.$request->id.'/cancel/'.$cancelToken),
        ]);
        $this->mailNewAddress($user, $request, $confirmToken);

        return $request;
    }

    /** A new link replaces the previous one; the warning to the old address is not repeated. */
    public function resend(User $user): EmailChangeRequest
    {
        $request = $this->pending($user) ?? throw new AccountRejected('email_change_not_pending', 404);
        $confirmToken = Str::random(48);

        if ($request->confirm_token_hash !== null) {
            $request->forceFill(['confirm_token_hash' => hash('sha256', $confirmToken)])->save();
        }

        $this->mailNewAddress($user, $request, $confirmToken);

        return $request;
    }

    public function cancel(User $user): void
    {
        $request = $this->pending($user) ?? throw new AccountRejected('email_change_not_pending', 404);
        $request->forceFill(['cancelled_at' => Carbon::now()])->save();
        $this->audit($user, 'user.email_change_cancelled', ['by' => 'user']);
    }

    /** From the new address; every session ends, so the next sign-in already uses the new address. */
    public function confirm(string $requestId, string $token): bool
    {
        return DB::transaction(function () use ($requestId, $token): bool {
            /** @var EmailChangeRequest|null $request */
            $request = Str::isUuid($requestId) ? EmailChangeRequest::query()->whereKey($requestId)->lockForUpdate()->first() : null;

            if ($request === null || ! $request->open() || $request->confirm_token_hash === null || ! hash_equals($request->confirm_token_hash, hash('sha256', $token))) {
                return false;
            }

            /** @var User $user */
            $user = User::query()->whereKey($request->user_id)->lockForUpdate()->firstOrFail();

            if ($this->taken($request->new_email, $user)) {
                $request->forceFill(['cancelled_at' => Carbon::now()])->save();

                return false;
            }

            $old = $user->email;
            $user->forceFill(['email' => $request->new_email, 'email_verified_at' => Carbon::now(), 'remember_token' => Str::random(60)])->save();
            $request->forceFill(['confirmed_at' => Carbon::now()])->save();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            DB::table('password_reset_tokens')->where('email', $old)->delete();
            $this->audit($user, 'user.email_changed', ['before' => self::mask($old), 'after' => self::mask($request->new_email), 'sessions_revoked' => true]);

            DB::afterCommit(fn () => $this->mailer->send($old, $user->locale, 'email_changed', ['email' => self::mask($request->new_email)]));

            return true;
        });
    }

    /** From the old address ("this was not me"): the change stops, every session and trusted device ends. */
    public function cancelFromOldAddress(string $requestId, string $token): bool
    {
        return DB::transaction(function () use ($requestId, $token): bool {
            /** @var EmailChangeRequest|null $request */
            $request = Str::isUuid($requestId) ? EmailChangeRequest::query()->whereKey($requestId)->lockForUpdate()->first() : null;

            if ($request === null || $request->confirmed_at !== null || ! hash_equals($request->cancel_token_hash, hash('sha256', $token))) {
                return false;
            }

            /** @var User $user */
            $user = User::query()->whereKey($request->user_id)->firstOrFail();
            $request->forceFill(['cancelled_at' => $request->cancelled_at ?? Carbon::now()])->save();
            $user->forceFill(['remember_token' => Str::random(60)])->save();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $this->trustedDevices->revokeAll($user);
            $this->audit($user, 'user.email_change_cancelled', ['by' => 'old_address', 'sessions_revoked' => true]);

            return true;
        });
    }

    /** "anna@example.com" → "a***@example.com": enough to recognise, not enough to collect. */
    public static function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1).'***@'.$domain;
    }

    private function mailNewAddress(User $user, EmailChangeRequest $request, string $confirmToken): void
    {
        if ($request->confirm_token_hash === null) {
            $this->mailer->send($request->new_email, $user->locale, 'email_change_address_taken', ['link' => $this->mailer->link('/app/forgot-password')]);

            return;
        }

        $this->mailer->send($request->new_email, $user->locale, 'email_change_confirm', [
            'link' => $this->mailer->link('/api/v1/auth/email-change/'.$request->id.'/confirm/'.$confirmToken),
            'hours' => self::TTL_HOURS,
        ]);
    }

    private function taken(string $email, User $user): bool
    {
        return User::query()->whereRaw('lower(email) = ?', [$email])->whereKeyNot($user->id)->exists();
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function audit(User $user, string $action, array $changes): void
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
