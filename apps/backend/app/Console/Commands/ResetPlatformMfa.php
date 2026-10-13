<?php

namespace App\Console\Commands;

use App\Console\Concerns\EnrollsPlatformMfa;
use App\Models\PlatformStaff;
use App\Models\User;
use App\Support\Account\MfaService;
use App\Support\Platform\PlatformAudit;
use Illuminate\Console\Command;

class ResetPlatformMfa extends Command
{
    use EnrollsPlatformMfa;

    protected $signature = 'platform:reset-mfa {email : Email of the staff account} {--reason= : Why the authenticator is reset}';

    protected $description = 'Reset the authenticator of a platform staff member, end their sessions and enrol a new one (run on the server only)';

    public function handle(MfaService $mfa, PlatformAudit $audit): int
    {
        $user = User::query()->where('email', mb_strtolower(trim((string) $this->argument('email'))))->first();
        $staff = $user === null ? null : PlatformStaff::query()->where('user_id', $user->id)->first();

        if ($user === null || $staff === null) {
            $this->error('No platform staff member with this email.');

            return self::FAILURE;
        }

        $reason = trim((string) ($this->option('reason') ?: $this->ask('Reason (kept in the audit)')));

        if (mb_strlen($reason) < 3) {
            $this->error('A reason of at least 3 characters is required.');

            return self::FAILURE;
        }

        if (! $this->confirm("Reset the authenticator of {$user->email} and end their sessions?", false)) {
            return self::FAILURE;
        }

        $mfa->disable($user);
        $staff->forceFill(['permission_version' => $staff->permission_version + 1])->save();
        $audit->record(null, 'staff.mfa_reset', 'staff', $staff->id, PlatformAudit::RESULT_SUCCEEDED, [], $reason);
        $this->info('The old authenticator no longer works and all sessions are ended.');

        return $this->enrollMfa($user, $mfa) ? self::SUCCESS : self::FAILURE;
    }
}
