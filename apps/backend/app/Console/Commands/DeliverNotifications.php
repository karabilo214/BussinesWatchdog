<?php

namespace App\Console\Commands;

use App\Support\Notifications\NotificationDeliveryWorker;
use Illuminate\Console\Command;

class DeliverNotifications extends Command
{
    protected $signature = 'notifications:deliver
        {--limit=50}
        {--sending-lease-seconds=300}';

    protected $description = 'Send due notification deliveries once, applying retry, dead-letter and uncertain-delivery rules.';

    public function handle(NotificationDeliveryWorker $worker): int
    {
        $result = $worker->deliverDue(
            limit: (int) $this->option('limit'),
            sendingLeaseSeconds: (int) $this->option('sending-lease-seconds'),
        );

        $this->components->info(sprintf(
            'Notification delivery complete: claimed=%d sent=%d failed=%d uncertain=%d dead_letter=%d suppressed=%d swept=%d',
            $result['claimed'],
            $result['sent'],
            $result['failed'],
            $result['uncertain'],
            $result['dead_letter'],
            $result['suppressed'],
            $result['swept'],
        ));

        return self::SUCCESS;
    }
}
