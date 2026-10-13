<?php

namespace App\Console\Concerns;

use App\Models\User;
use App\Support\Account\AccountRejected;
use App\Support\Account\MfaService;

trait EnrollsPlatformMfa
{
    protected function enrollMfa(User $user, MfaService $mfa): bool
    {
        $setup = $mfa->beginSetup($user);
        $this->line('Add this account to an authenticator app:');
        $this->line($setup['otpauth_uri']);
        $this->line('Secret: '.$setup['secret']);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $codes = $mfa->confirm($user, (string) $this->ask('Code from the app'));
            } catch (AccountRejected) {
                $this->error('The code is not valid.');

                continue;
            }

            $this->info('Two-factor authentication is on. Keep these recovery codes offline; each works once:');

            foreach ($codes as $code) {
                $this->line($code);
            }

            return true;
        }

        $this->error('Two-factor authentication was not set up; run the command again.');

        return false;
    }
}
