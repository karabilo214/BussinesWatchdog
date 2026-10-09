<?php

namespace App\Support\Notifications;

use App\Models\DomainOutbox;
use App\Models\Incident;
use Illuminate\Support\Carbon;

class IncidentNotificationRequester
{
    public function request(Incident $incident, string $kind): void
    {
        $now = Carbon::now();

        DomainOutbox::query()->firstOrCreate(
            [
                'tenant_id' => $incident->tenant_id,
                'topic' => DomainOutbox::TOPIC_INCIDENT_NOTIFICATION_REQUESTED,
                'dedupe_key' => self::dedupeKey($incident->id, $incident->revision, $kind),
            ],
            [
                'payload' => [
                    'incident_id' => $incident->id,
                    'incident_revision' => $incident->revision,
                    'notification_kind' => $kind,
                ],
                'status' => DomainOutbox::STATUS_PENDING,
                'attempts' => 0,
                'next_attempt_at' => $now,
                'created_at' => $now,
            ],
        );
    }

    public static function dedupeKey(string $incidentId, int $revision, string $kind): string
    {
        return 'incident:'.$incidentId.':rev:'.$revision.':'.$kind;
    }
}
