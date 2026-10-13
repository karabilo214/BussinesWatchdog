<?php

namespace App\Console\Commands;

use App\Console\Concerns\EnrollsPlatformMfa;
use App\Models\PlatformStaff;
use App\Models\User;
use App\Support\Account\MfaService;
use App\Support\Platform\PlatformAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreatePlatformOwner extends Command
{
    use EnrollsPlatformMfa;

    protected $signature = 'platform:create-owner {email : Email of the owner account} {--name= : Name for a new account}';

    protected $description = 'Create or attach a platform owner and set up two-factor authentication (run on the server only)';

    public function handle(MfaService $mfa, PlatformAudit $audit): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));

        if (Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:254']])->fails()) {
            $this->error('The email address is not valid.');

            return self::FAILURE;
        }

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $user = $this->createUser($email);

            if ($user === null) {
                return self::FAILURE;
            }
        } elseif (! $this->confirm("An account with {$email} exists. Make it a platform owner?", false)) {
            return self::FAILURE;
        }

        $staff = DB::transaction(function () use ($user, $audit): PlatformStaff {
            $staff = PlatformStaff::query()->where('user_id', $user->id)->lockForUpdate()->first();

            if ($staff === null) {
                $staff = PlatformStaff::query()->create(['user_id' => $user->id, 'role' => PlatformStaff::ROLE_OWNER, 'state' => PlatformStaff::STATE_ACTIVE]);
                $audit->record(null, 'staff.bootstrap_owner', 'staff', $staff->id, PlatformAudit::RESULT_SUCCEEDED, ['role' => PlatformStaff::ROLE_OWNER]);
            }

            return $staff;
        });

        if ($staff->role !== PlatformStaff::ROLE_OWNER || ! $staff->active()) {
            $this->error('This account is already platform staff with another role or disabled; change it in the control panel.');

            return self::FAILURE;
        }

        if (! $mfa->enabled($user)) {
            return $this->enrollMfa($user, $mfa) ? self::SUCCESS : self::FAILURE;
        }

        $this->info('The platform owner is ready. Sign in at /owner with the existing authenticator.');

        return self::SUCCESS;
    }

    private function createUser(string $email): ?User
    {
        $name = trim((string) ($this->option('name') ?: $this->ask('Name')));
        $password = (string) $this->secret('Password (at least 12 characters)');

        if ($password !== (string) $this->secret('Repeat the password')) {
            $this->error('The passwords do not match.');

            return null;
        }

        $validator = Validator::make(['name' => $name, 'password' => $password], [
            'name' => ['required', 'string', 'max:100'],
            'password' => ['required', 'max:128', Password::min(12)],
        ]);

        if ($validator->fails()) {
            $this->error(implode(' ', $validator->errors()->all()));

            return null;
        }

        $user = User::query()->create(['name' => $name, 'email' => $email, 'password_hash' => Hash::make($password), 'locale' => 'ru']);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }
}
