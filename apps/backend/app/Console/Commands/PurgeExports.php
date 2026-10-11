<?php

namespace App\Console\Commands;

use App\Support\Exports\CsvExportService;
use Illuminate\Console\Command;

class PurgeExports extends Command
{
    protected $signature = 'exports:purge {--limit=500}';

    protected $description = 'Delete expired export files from private storage and mark them deleted.';

    public function handle(CsvExportService $exports): int
    {
        $purged = $exports->purgeExpired(max(1, (int) $this->option('limit')));
        $this->components->info(sprintf('Exports purged: %d', $purged));

        return self::SUCCESS;
    }
}
