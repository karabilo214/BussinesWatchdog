<?php

namespace App\Support\Outbox;

use App\Models\DomainOutbox;
use App\Support\Ingest\EventInboxProcessor;
use App\Support\Notifications\IncidentNotificationPlanner;

class DomainOutboxDispatcher
{
    public const ERROR_TOPIC_UNSUPPORTED = 'outbox_topic_unsupported';

    public const ERROR_EVENT_INBOX_ID_MISSING = 'event_inbox_id_missing';

    public const ERROR_EVENT_INBOX_UNPROCESSABLE = 'event_inbox_unprocessable';

    public const ERROR_NOTIFICATION_PAYLOAD_INVALID = 'notification_payload_invalid';

    public function __construct(
        private readonly DomainOutboxLeaser $leaser,
        private readonly DomainOutboxResultRecorder $resultRecorder,
        private readonly EventInboxProcessor $eventInboxProcessor,
        private readonly DomainOutboxSweeper $sweeper,
        private readonly IncidentNotificationPlanner $notificationPlanner,
    ) {
    }

    /**
     * @return array{leased: int, published: int, failed: int}
     */
    public function dispatchDue(int $limit = 50, int $leaseSeconds = 60): array
    {
        $this->sweeper->releaseExpiredLeases();

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
        return match ($message->topic) {
            DomainOutbox::TOPIC_EVENT_INBOX_RECEIVED => $this->dispatchEventInboxReceived($message),
            DomainOutbox::TOPIC_INCIDENT_NOTIFICATION_REQUESTED => $this->dispatchIncidentNotificationRequested($message),
            default => $this->rejectUnsupportedTopic($message),
        };
    }

    private function rejectUnsupportedTopic(DomainOutbox $message): bool
    {
        $this->resultRecorder->markFailed($message->id, self::ERROR_TOPIC_UNSUPPORTED);

        return false;
    }

    private function dispatchIncidentNotificationRequested(DomainOutbox $message): bool
    {
        $incidentId = $message->payload['incident_id'] ?? null;
        $revision = $message->payload['incident_revision'] ?? null;
        $kind = $message->payload['notification_kind'] ?? null;

        if (! is_string($incidentId) || ! is_int($revision) || ! is_string($kind)) {
            $this->resultRecorder->markFailed($message->id, self::ERROR_NOTIFICATION_PAYLOAD_INVALID);

            return false;
        }

        if ($this->notificationPlanner->plan($message->tenant_id, $incidentId, $revision, $kind) === null) {
            $this->resultRecorder->markFailed(
                $message->id,
                $this->notificationPlanner->lastErrorCode() ?? self::ERROR_NOTIFICATION_PAYLOAD_INVALID,
            );

            return false;
        }

        return $this->resultRecorder->markPublished($message->id);
    }

    private function dispatchEventInboxReceived(DomainOutbox $message): bool
    {
        $eventInboxId = $message->payload['event_inbox_id'] ?? null;

        if (! is_string($eventInboxId) || $eventInboxId === '') {
            $this->resultRecorder->markFailed($message->id, self::ERROR_EVENT_INBOX_ID_MISSING);

            return false;
        }

        if (! $this->eventInboxProcessor->processReceived($eventInboxId)) {
            $this->resultRecorder->markFailed(
                $message->id,
                $this->eventInboxProcessor->lastErrorCode() ?? self::ERROR_EVENT_INBOX_UNPROCESSABLE,
            );

            return false;
        }

        return $this->resultRecorder->markPublished($message->id);
    }
}
