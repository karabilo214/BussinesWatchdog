<?php

namespace App\Support\Notifications;

use App\Models\Incident;
use App\Models\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Models\Store;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class IncidentReminderPlanner
{
    public function __construct(
        private readonly IncidentNotificationContentBuilder $contentBuilder,
    ) {}

    public function planDue(int $limit = 100): int
    {
        $planned = 0;

        $incidents = Incident::query()
            ->whereIn('state', Incident::ACTIVE_STATES)
            ->orderBy('last_seen_at')
            ->orderBy('id')
            ->limit(max(1, min($limit, 500)))
            ->get();

        foreach ($incidents as $incident) {
            $planned += $this->planForIncident($incident);
        }

        return $planned;
    }

    private function planForIncident(Incident $incident): int
    {
        if ($incident->isActiveSuppressed()) {
            return 0;
        }

        /** @var Tenant $tenant */
        $tenant = Tenant::query()->whereKey($incident->tenant_id)->firstOrFail();
        /** @var Store $store */
        $store = Store::query()
            ->where('tenant_id', $incident->tenant_id)
            ->whereKey($incident->store_id)
            ->firstOrFail();

        $channels = NotificationChannel::query()
            ->where('tenant_id', $incident->tenant_id)
            ->where('enabled', true)
            ->whereNotNull('verified_at')
            ->orderBy('id')
            ->get();

        $now = Carbon::now();
        $created = 0;

        foreach ($channels as $channel) {
            $preferences = NotificationPreferences::fromArray(
                $channel->preferences ?? [],
                (string) $tenant->locale,
                (string) $tenant->timezone,
            );

            if ($preferences->reminderHours === null
                || ! $preferences->allowsStore($store->id)
                || ! $preferences->allowsSeverity($incident->severity)) {
                continue;
            }

            $lastSentAt = NotificationDelivery::query()
                ->where('tenant_id', $incident->tenant_id)
                ->where('channel_id', $channel->id)
                ->where('incident_id', $incident->id)
                ->whereIn('notification_kind', [
                    NotificationDelivery::KIND_INCIDENT_OPENED,
                    NotificationDelivery::KIND_INCIDENT_REOPENED,
                    NotificationDelivery::KIND_INCIDENT_REMINDER,
                ])
                ->where('status', NotificationDelivery::STATUS_SENT)
                ->whereNotNull('sent_at')
                ->max('sent_at');

            if ($lastSentAt === null || Carbon::parse($lastSentAt)->addHours($preferences->reminderHours)->greaterThan($now)) {
                continue;
            }

            $windowStart = $now->copy()
                ->setTimestamp(intdiv($now->timestamp, $preferences->reminderHours * 3600) * $preferences->reminderHours * 3600)
                ->startOfSecond();
            $dedupeKey = sprintf('incident:%s:reminder:%s', $incident->id, $windowStart->format('YmdHis'));

            $delivery = DB::transaction(fn (): NotificationDelivery => NotificationDelivery::query()->firstOrCreate(
                [
                    'tenant_id' => $incident->tenant_id,
                    'channel_id' => $channel->id,
                    'dedupe_key' => $dedupeKey,
                ],
                [
                    'incident_id' => $incident->id,
                    'notification_kind' => NotificationDelivery::KIND_INCIDENT_REMINDER,
                    'incident_revision' => $incident->revision,
                    'template_version' => IncidentNotificationContentBuilder::TEMPLATE_VERSION,
                    'sanitized_content' => $this->contentBuilder->forIncident($incident, $store, NotificationDelivery::KIND_INCIDENT_REMINDER, $preferences),
                    'status' => NotificationDelivery::STATUS_QUEUED,
                    'attempts' => 0,
                    'next_attempt_at' => $preferences->nextAllowedAt($now, $incident->severity),
                    'error_code' => null,
                    'created_at' => $now,
                ],
            ));

            if ($delivery->wasRecentlyCreated) {
                $created++;
            }
        }

        return $created;
    }
}
