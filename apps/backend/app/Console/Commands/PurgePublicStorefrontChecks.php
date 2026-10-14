<?php

namespace App\Console\Commands;

use App\Support\Site\PublicStorefrontCheckService;
use Illuminate\Console\Command;

class PurgePublicStorefrontChecks extends Command
{
    protected $signature = 'site:public-checks-purge {--limit=200}';

    protected $description = 'Delete expired public storefront check screenshots and diagnostics.';

    public function handle(PublicStorefrontCheckService $checks): int
    {
        $purged = $checks->purgeExpired(max(1, (int) $this->option('limit')));
        $this->components->info(sprintf('Public storefront checks purged: %d', $purged));

        return self::SUCCESS;
    }
}
