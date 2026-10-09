<?php

namespace App\Console\Commands;

use App\Support\Browser\ArtifactStore;
use Illuminate\Console\Command;

class PurgeArtifacts extends Command
{
    protected $signature = 'artifacts:purge {--limit=500}';

    protected $description = 'Delete expired check artifacts from object storage and mark them deleted.';

    public function handle(ArtifactStore $artifacts): int
    {
        $purged = $artifacts->purgeExpired(max(1, (int) $this->option('limit')));
        $this->components->info(sprintf('Artifacts purged: %d', $purged));

        return self::SUCCESS;
    }
}
