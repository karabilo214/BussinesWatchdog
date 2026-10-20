<?php

namespace App\Support\Notifications;

use App\Models\Incident;
use App\Models\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Models\Store;
use App\Models\Tenant;
use App\Support\Platform\MaintenanceWindow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Plans at most one daily digest per channel and local day (ADR 0067): on the first run after the channel's
 * `daily_digest_at` in its own timezone. Quiet hours defer the digest, the maintenance window holds planning back.
 */
class DailyDigestPlanner
{
    public function __construct(
        private readonly DailyDigestContentBuilder $contentBuilder,
    ) {}

    public function planDue(): int
    {
        if (app(MaintenanceWindow::class)->active()) {
            return 0;
        }

        $now = Carbon::now();
        $planned = 0;
        $tenants = [];

        NotificationChannel::query()
            ->where('enabled', true)
            ->whereNotNull('verified_at')
            ->chunkById(200, function (Collection $channels) use ($now, &$planned, &$tenants): void {
                foreach ($channels as $channel) {
                    $tenant = $tenants[$channel->tenant_id] ??= Tenant::query()->whereKey($channel->tenant_id)->first();

                    if ($tenant !== null && $this->planForChannel($tenant, $channel, $now)) {
                        $planned++;
                    }
                }
            });

        return $planned;
    }

    private function planForChannel(Tenant $tenant, NotificationChannel $channel, Carbon $now): bool
    {
        $preferences = NotificationPreferences::fromArray(
            $channel->preferences ?? [],
            (string) $tenant->locale,
            (string) $tenant->timezone,
        );

        if ($preferences->dailyDigestAt === null) {
            return false;
        }

        $local = $now->copy()->setTimezone($preferences->timezone);
        $minutes = (int) NotificationPreferences::minutesOfDay($preferences->dailyDigestAt);

        if ($local->hour * 60 + $local->minute < $minutes) {
            return false;
        }

        $dedupeKey = 'digest:'.$local->format('Y-m-d');
        $exists = NotificationDelivery::query()
            ->where('tenant_id', $tenant->id)
            ->where('channel_id', $channel->id)
            ->where('dedupe_key', $dedupeKey)
            ->exists();

        if ($exists) {
            return false;
        }

        $stores = Store::query()
            ->where('tenant_id', $tenant->id)
            ->whereNotIn('status', ['deleted', 'deleting'])
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->filter(fn (Store $store): bool => $preferences->allowsStore($store->id))
            ->values();

        if ($stores->isEmpty()) {
            return false;
        }

        $delivery = DB::transaction(fn (): NotificationDelivery => NotificationDelivery::query()->firstOrCreate(
            [
                'tenant_id' => $tenant->id,
                'channel_id' => $channel->id,
                'dedupe_key' => $dedupeKey,
            ],
            [
                'incident_id' => null,
                'notification_kind' => NotificationDelivery::KIND_DAILY_DIGEST,
                'incident_revision' => null,
                'template_version' => DailyDigestContentBuilder::TEMPLATE_VERSION,
                'sanitized_content' => $this->contentBuilder->forChannel($tenant, $stores, $preferences, $now),
                'status' => NotificationDelivery::STATUS_QUEUED,
                'attempts' => 0,
                'next_attempt_at' => $preferences->nextAllowedAt($now, Incident::SEVERITY_INFO),
                'error_code' => null,
                'created_at' => $now,
            ],
        ));

        return $delivery->wasRecentlyCreated;
    }
}
