<?php

namespace App\Console\Commands;

use App\Models\PlatformStaff;
use App\Support\Site\SeoPageImporter;
use App\Support\Site\SiteRejected;
use Illuminate\Console\Command;

class CreateSeoPages extends Command
{
    protected $signature = 'site:seo-pages
        {staff? : Active platform owner email; otherwise use the first active owner}
        {--publish= : Publish new or unchanged initial drafts with this audit reason}
        {--if-owner : Skip on a fresh installation that has no owner yet}';

    protected $description = 'Import nine editable troubleshooting guides in ru/en/de without overwriting editorial changes';

    public function handle(SeoPageImporter $importer): int
    {
        $query = PlatformStaff::query()->where('role', PlatformStaff::ROLE_OWNER)->where('state', PlatformStaff::STATE_ACTIVE)
            ->whereHas('user', fn ($user) => $user->whereNull('disabled_at'));
        if ($email = $this->argument('staff')) {
            $query->whereHas('user', fn ($user) => $user->where('email', mb_strtolower(trim((string) $email))));
        }
        $staff = $query->orderBy('created_at')->orderBy('id')->first();
        if ($staff === null) {
            if ($this->option('if-owner') && ! $email) {
                $this->warn('SEO guides skipped: bootstrap an active platform owner first.');

                return self::SUCCESS;
            }
            $this->error('No active platform owner.');

            return self::FAILURE;
        }
        $reason = $this->option('publish');
        if ($reason !== null && (mb_strlen(trim((string) $reason)) < 3 || mb_strlen(trim((string) $reason)) > 500)) {
            $this->error('Publication requires a reason of 3–500 characters.');

            return self::FAILURE;
        }
        try {
            $counts = $importer->import($staff->id, $reason === null ? null : trim((string) $reason));
        } catch (SiteRejected $error) {
            $this->error($error->reasonCode);

            return self::FAILURE;
        }
        foreach ($counts as $name => $count) {
            $this->line($name.': '.$count);
        }

        return self::SUCCESS;
    }
}
