<?php

namespace App\Console\Commands;

use App\Support\Privacy\OrganizationPurger;
use Illuminate\Console\Command;

class ProcessOrganizationDeletions extends Command
{
    protected $signature = 'privacy:process-deletions {--limit=5}';

    protected $description = 'Continue the deletion of organizations whose 30 days have passed (ADR 0069); every run resumes where the last one stopped.';

    public function handle(OrganizationPurger $purger): int
    {
        $result = $purger->processDue((int) $this->option('limit'));

        $this->components->info(sprintf('Organization deletions: processed=%d completed=%d failed=%d', $result['processed'], $result['completed'], $result['failed']));

        return self::SUCCESS;
    }
}
