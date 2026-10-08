<?php

namespace App\Support\Outbox;

use App\Models\DomainOutbox;
use App\Support\Ingest\EventInboxProcessor;

class DomainOutboxDispatcher
{
    public function __construct(
        private readonly DomainOutboxLeaser $leaser,
        private readonly DomainOutboxResultRecorder $resultRecorder,
        private readonly EventInboxProcessor $eventInboxProcessor,
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
            DomainOutbox::TOPIC_EVENT_INBOX_RECEIVED => $this->dispatchEventInboxReceived($message),
            default => $this->resultRecorder->markFailed($message->id, 'outbox_topic_unsupported'),
        };

        return $handled && $message->topic === DomainOutbox::TOPIC_EVENT_INBOX_RECEIVED;
    }

    private function dispatchEventInboxReceived(DomainOutbox $message): bool
    {
        $eventInboxId = $message->payload['event_inbox_id'] ?? null;

        if (! is_string($eventInboxId) || $eventInboxId === '') {
            $this->resultRecorder->markFailed($message->id, 'event_inbox_id_missing');

            return false;
        }

        if (! $this->eventInboxProcessor->processReceived($eventInboxId)) {
            $this->resultRecorder->markFailed(
                $message->id,
                $this->eventInboxProcessor->lastErrorCode() ?? 'event_inbox_unprocessable',
            );

            return false;
        }

        return $this->resultRecorder->markPublished($message->id);
    }
}
