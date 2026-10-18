<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PurgePlatformAudit extends Command
{
    private const CHUNK = 1000;

    protected $signature = 'platform:audit-purge';

    protected $description = 'Delete platform audit entries older than the retention period (ADR 0053, two years by default)';

    public function handle(): int
    {
        $days = max(1, (int) config('watchdog.platform.audit_retention_days'));
        $before = Carbon::now()->subDays($days);
        $deleted = 0;

        do {
            $ids = DB::table('platform_audit_log')->where('created_at', '<', $before)->orderBy('created_at')->limit(self::CHUNK)->pluck('id');
            $deleted += $ids->isEmpty() ? 0 : DB::table('platform_audit_log')->whereIn('id', $ids)->delete();
        } while ($ids->count() === self::CHUNK);

        $this->line(sprintf('deleted=%d before=%s', $deleted, $before->toDateString()));

        return self::SUCCESS;
    }
}
