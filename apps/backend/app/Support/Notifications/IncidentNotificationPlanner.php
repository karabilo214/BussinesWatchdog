<?php

namespace App\Support\Notifications;

use App\Models\Incident;
use App\Models\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Models\Store;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class IncidentNotificationPlanner
{
    public const ERROR_INCIDENT_NOT_FOUND = 'notification_incident_not_found';

    public const ERROR_INVALID_KIND = 'notification_kind_invalid';

    public const REASON_INCIDENT_SUPPRESSED = 'incident_suppressed';

    private ?string $lastErrorCode = null;

    public function __construct(
        private readonly IncidentNotificationContentBuilder $contentBuilder,
    ) {}

    /**
     * @return int|null number of deliveries newly created, null on failure (see lastErrorCode())
     */
    public function plan(string $tenantId, string $incidentId, int $revision, string $kind): ?int
    {
        $this->lastErrorCode = null;

        if (! in_array($kind, NotificationDelivery::INCIDENT_KINDS, true)) {
            $this->lastErrorCode = self::ERROR_INVALID_KIND;

            return null;
        }

        /** @var Incident|null $incident */
        $incident = Incident::query()->where('tenant_id', $tenantId)->whereKey($incidentId)->first();

        if ($incident === null) {
            $this->lastErrorCode = self::ERROR_INCIDENT_NOT_FOUND;

            return null;
        }

        /** @var Store $store */
        $store = Store::query()->where('tenant_id', $tenantId)->whereKey($incident->store_id)->firstOrFail();
        /** @var Tenant $tenant */
        $tenant = Tenant::query()->whereKey($tenantId)->firstOrFail();

        $channels = NotificationChannel::query()
            ->where('tenant_id', $tenantId)
            ->where('enabled', true)
            ->whereNotNull('verified_at')
            ->orderBy('id')
            ->get();

        $suppressed = $incident->isActiveSuppressed();
        $dedupeKey = IncidentNotificationRequester::dedupeKey($incident->id, $revision, $kind);
        $now = Carbon::now();
        $created = 0;

        foreach ($channels as $channel) {
            $preferences = NotificationPreferences::fromArray(
                $channel->preferences ?? [],
                (string) $tenant->locale,
                (string) $tenant->timezone,
            );

            if (! $preferences->allowsStore($store->id) || ! $preferences->allowsSeverity($incident->severity)) {
                continue;
            }

            if ($kind === NotificationDelivery::KIND_INCIDENT_RECOVERED && ! $preferences->notifyRecovery) {
                continue;
            }

            $delivery = DB::transaction(fn (): NotificationDelivery => NotificationDelivery::query()->firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'channel_id' => $channel->id,
                    'dedupe_key' => $dedupeKey,
                ],
                [
                    'incident_id' => $incident->id,
                    'notification_kind' => $kind,
                    'incident_revision' => $revision,
                    'template_version' => IncidentNotificationContentBuilder::TEMPLATE_VERSION,
                    'sanitized_content' => $this->contentBuilder->forIncident($incident, $store, $kind, $preferences),
                    'status' => $suppressed ? NotificationDelivery::STATUS_SUPPRESSED : NotificationDelivery::STATUS_QUEUED,
                    'attempts' => 0,
                    'next_attempt_at' => $preferences->nextAllowedAt($now, $incident->severity),
                    'error_code' => $suppressed ? self::REASON_INCIDENT_SUPPRESSED : null,
                    'created_at' => $now,
                ],
            ));

            if ($delivery->wasRecentlyCreated) {
                $created++;
            }
        }

        return $created;
    }

    public function lastErrorCode(): ?string
    {
        return $this->lastErrorCode;
    }
}
