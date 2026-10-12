<?php

namespace App\Console\Commands;

use App\Support\Mail\MailAuthenticationInspector;
use Illuminate\Console\Command;

class CheckMailAuthentication extends Command
{
    protected $signature = 'mail:auth-check {--strict : Fail when any production mail authentication check is missing}';

    protected $description = 'Check DKIM, SPF and DMARC requirements for production email delivery.';

    public function handle(MailAuthenticationInspector $inspector): int
    {
        $result = $inspector->inspect();

        foreach ($result['checks'] as $name => $check) {
            $this->line(sprintf('%s=%s %s', $name, $check['ok'] ? 'ok' : 'missing', $check['message']));
        }

        $strict = $this->option('strict') || app()->environment('production');

        if (! $result['ok'] && $strict) {
            $this->error('Production email authentication is incomplete.');

            return self::FAILURE;
        }

        $this->info($result['ok'] ? 'Production email authentication is complete.' : 'Production email authentication is incomplete.');

        return self::SUCCESS;
    }
}
