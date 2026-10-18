<?php

namespace App\Support\Platform;

use App\Models\PlatformStaff;
use App\Models\PlatformStaffInvitation;
use App\Models\User;
use App\Support\Account\AccountRejected;
use App\Support\Account\MfaService;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class StaffOnboarding
{
    public const SESSION = 'platform_staff_invitation';

    public function __construct(
        private readonly MfaService $mfa,
        private readonly PlatformAudit $audit,
    ) {}

    public function find(string $token): PlatformStaffInvitation
    {
        /** @var PlatformStaffInvitation|null $invitation */
        $invitation = PlatformStaffInvitation::query()->where('token_hash', hash('sha256', $token))->first();

        if ($invitation === null || ! $invitation->pending()) {
            throw new StaffRejected('invitation_invalid');
        }

        return $invitation;
    }

    /**
     * @return array{email: string, role: string, capabilities: list<string>|null, expires_at: string, account_exists: bool}
     */
    public function lookup(string $token): array
    {
        $invitation = $this->find($token);

        return [
            'email' => $invitation->email,
            'role' => $invitation->role,
            'capabilities' => $invitation->capabilities,
            'expires_at' => $invitation->expires_at->toJSON(),
            'account_exists' => User::query()->where('email', $invitation->email)->exists(),
        ];
    }

    /**
     * @return array{mfa_enabled: bool, otpauth_uri?: string, secret?: string, qr_svg?: string}
     */
    public function start(Session $session, string $token, ?string $name, string $password): array
    {
        $invitation = $this->find($token);
        $user = User::query()->where('email', $invitation->email)->first();

        if ($user !== null) {
            if (! Hash::check($password, (string) $user->password_hash)) {
                throw new StaffRejected('password_wrong');
            }

            if (PlatformStaff::query()->where('user_id', $user->id)->exists()) {
                throw new StaffRejected('already_staff', 409);
            }
        } else {
            if ($name === null || trim($name) === '') {
                throw new StaffRejected('name_required');
            }

            $user = User::query()->create(['name' => trim($name), 'email' => $invitation->email, 'password_hash' => Hash::make($password), 'locale' => 'ru']);
            $user->forceFill(['email_verified_at' => Carbon::now()])->save();
        }

        $session->put(self::SESSION, ['invitation_id' => $invitation->id, 'user_id' => $user->id]);

        if ($this->mfa->enabled($user)) {
            return ['mfa_enabled' => true];
        }

        try {
            return ['mfa_enabled' => false] + $this->mfa->beginSetup($user);
        } catch (AccountRejected) {
            return ['mfa_enabled' => true];
        }
    }

    /**
     * @return array{staff_id: string, recovery_codes: list<string>}
     */
    public function confirm(Session $session, string $token, string $code): array
    {
        $invitation = $this->find($token);
        $pending = $session->get(self::SESSION);

        if (! is_array($pending) || ($pending['invitation_id'] ?? null) !== $invitation->id) {
            throw new StaffRejected('invitation_not_started', 409);
        }

        /** @var User $user */
        $user = User::query()->findOrFail($pending['user_id']);
        $codes = [];

        if ($this->mfa->enabled($user)) {
            if (! $this->mfa->verify($user, $code)) {
                throw new StaffRejected('mfa_code_invalid');
            }
        } else {
            try {
                $codes = $this->mfa->confirm($user, $code);
            } catch (AccountRejected) {
                throw new StaffRejected('mfa_code_invalid');
            }
        }

        $staff = DB::transaction(function () use ($invitation, $user): PlatformStaff {
            /** @var PlatformStaffInvitation $locked */
            $locked = PlatformStaffInvitation::query()->whereKey($invitation->id)->lockForUpdate()->firstOrFail();

            if (! $locked->pending() || PlatformStaff::query()->where('user_id', $user->id)->exists()) {
                throw new StaffRejected('invitation_invalid');
            }

            $staff = PlatformStaff::query()->create(['user_id' => $user->id, 'role' => $locked->role, 'capabilities' => $locked->capabilities, 'state' => PlatformStaff::STATE_ACTIVE]);
            $locked->forceFill(['accepted_at' => Carbon::now(), 'accepted_staff_id' => $staff->id])->save();
            $this->audit->record($staff->id, 'staff.joined', 'staff', $staff->id, PlatformAudit::RESULT_SUCCEEDED, ['role' => $locked->role, 'capabilities' => $locked->capabilities, 'invitation_id' => $locked->id]);

            return $staff;
        });
        $session->forget(self::SESSION);

        return ['staff_id' => $staff->id, 'recovery_codes' => $codes];
    }
}
