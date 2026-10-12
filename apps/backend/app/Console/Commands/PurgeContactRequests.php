<?php

namespace App\Console\Commands;

use App\Support\Site\ContactInbox;
use Illuminate\Console\Command;

class PurgeContactRequests extends Command
{
    protected $signature = 'site:contact-purge';

    protected $description = 'Delete closed contact form messages older than the retention period';

    public function handle(ContactInbox $inbox): int
    {
        $this->line('deleted='.$inbox->purge());

        return self::SUCCESS;
    }
}
