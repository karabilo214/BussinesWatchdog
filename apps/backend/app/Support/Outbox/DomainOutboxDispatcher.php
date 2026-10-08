<?php

namespace App\Support\Outbox;

use App\Models\DomainOutbox;

class DomainOutboxDispatcher
{
    public function __construct(
        private readonly DomainOutboxLeaser $leaser,
        private readonly DomainOutboxResultRecorder $resultRecorder,
    ) {
    }

    /**
     * @return array{leased: int, published: int, failed: int}
     */
    public function dispatchDue(int $limit = 50, int $leaseSeconds = 60): array
    {
        $leased = $this->leaser->leaseDue($limit, $leaseSeconds);
        $published = 0;
        $failed = 0;

        foreach ($leased as $message) {
            if ($this->dispatchMessage($message)) {
                $published++;
            } else {
                $failed++;
            }
        }

        return [
            'leased' => $leased->count(),
            'published' => $published,
            'failed' => $failed,
        ];
    }

    private function dispatchMessage(DomainOutbox $message): bool
    {
        $handled = match ($message->topic) {
            DomainOutbox::TOPIC_EVENT_INBOX_RECEIVED => $this->resultRecorder->markPublished($message->id),
            default => $this->resultRecorder->markFailed($message->id, 'outbox_topic_unsupported'),
        };

        return $handled && $message->topic === DomainOutbox::TOPIC_EVENT_INBOX_RECEIVED;
    }
}
