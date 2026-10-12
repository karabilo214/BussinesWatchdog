<?php

namespace App\Console\Commands;

use App\Support\Site\ContactInbox;
use Illuminate\Console\Command;

class NotifyContactRequests extends Command
{
    protected $signature = 'site:contact-notify';

    protected $description = 'Email the platform owner about new messages from the contact form';

    public function handle(ContactInbox $inbox): int
    {
        $result = $inbox->notifyPending();
        $this->line(sprintf('sent=%d failed=%d', $result['sent'], $result['failed']));

        return self::SUCCESS;
    }
}
