<?php

namespace App\Support\Incidents;

use App\Models\Incident;
use App\Models\IncidentActivity;
use App\Models\IncidentSignal;
use App\Models\NotificationDelivery;
use App\Models\Signal;
use App\Support\Notifications\IncidentNotificationRequester;
use Illuminate\Support\Carbon;

class IncidentRecorder
{
    public const REOPEN_WINDOW_HOURS = 24;

    public function __construct(
        private readonly IncidentNotificationRequester $notifications,
    ) {}

    /**
     * Opens an incident for the fingerprint, reopens one resolved within the reopen window, or
     * attaches the signal to the active one. Notifies only on open and reopen.
     *
     * @param  array{family: string, component: string, title_code: string, severity: string, first_bad_at?: ?Carbon, last_good_at?: ?Carbon}  $incident
     */
    public function openOrAttach(string $tenantId, string $storeId, string $fingerprint, array $incident, Signal $signal, string $associationReason): Incident
    {
        $now = Carbon::now();
        $notificationKind = null;
        $active = $this->active($tenantId, $storeId, $fingerprint);

        if ($active === null) {
            $recentlyResolved = Incident::query()
                ->where('tenant_id', $tenantId)
                ->where('store_id', $storeId)
                ->where('fingerprint', $fingerprint)
                ->where('state', Incident::STATE_RESOLVED)
                ->where('resolved_at', '>=', $now->copy()->subHours(self::REOPEN_WINDOW_HOURS))
                ->orderByDesc('resolved_at')
                ->lockForUpdate()
                ->first();

            if ($recentlyResolved !== null) {
                $recentlyResolved->forceFill([
                    'state' => Incident::STATE_OPEN,
                    'title_code' => $incident['title_code'],
                    'resolved_at' => null,
                    'resolution_reason' => null,
                    'last_seen_at' => $now,
                    'revision' => $recentlyResolved->revision + 1,
                    'updated_at' => $now,
                ])->save();
                $this->activity($recentlyResolved, IncidentActivity::KIND_REOPENED, []);
                $active = $recentlyResolved;
                $notificationKind = NotificationDelivery::KIND_INCIDENT_REOPENED;
            }
        }

        if ($active === null) {
            $active = Incident::query()->create([
                'tenant_id' => $tenantId,
                'store_id' => $storeId,
                'family' => $incident['family'],
                'component' => $incident['component'],
                'fingerprint' => $fingerprint,
                'state' => Incident::STATE_OPEN,
                'severity' => $incident['severity'],
                'title_code' => $incident['title_code'],
                'currency' => null,
                'verified_discrepancy_minor' => null,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'last_good_at' => $incident['last_good_at'] ?? null,
                'first_bad_at' => $incident['first_bad_at'] ?? $now,
                'revision' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->activity($active, IncidentActivity::KIND_CREATED, []);
            $notificationKind = NotificationDelivery::KIND_INCIDENT_OPENED;
        } elseif ($notificationKind === null) {
            $active->forceFill([
                'title_code' => $incident['title_code'],
                'last_seen_at' => $now,
                'revision' => $active->revision + 1,
                'updated_at' => $now,
            ])->save();
            $this->activity($active, IncidentActivity::KIND_SIGNAL_LINKED, []);
        }

        $this->link($active, $signal, $associationReason, $now);

        if ($notificationKind !== null) {
            $this->notifications->request($active, $notificationKind);
        }

        return $active;
    }

    public function resolve(string $tenantId, string $storeId, string $fingerprint, string $reason, Signal $signal, ?Carbon $lastGoodAt = null): ?Incident
    {
        $incident = $this->active($tenantId, $storeId, $fingerprint);

        if ($incident === null) {
            return null;
        }

        $now = Carbon::now();
        $incident->forceFill([
            'state' => Incident::STATE_RESOLVED,
            'resolved_at' => $now,
            'resolution_reason' => $reason,
            'last_good_at' => $lastGoodAt ?? $now,
            'last_seen_at' => $now,
            'revision' => $incident->revision + 1,
            'updated_at' => $now,
        ])->save();

        $this->link($incident, $signal, $reason, $now);
        $this->activity($incident, IncidentActivity::KIND_RESOLVED, ['reason' => $reason]);
        $this->notifications->request($incident, NotificationDelivery::KIND_INCIDENT_RECOVERED);

        return $incident;
    }

    public function active(string $tenantId, string $storeId, string $fingerprint): ?Incident
    {
        return Incident::query()
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where('fingerprint', $fingerprint)
            ->whereIn('state', Incident::ACTIVE_STATES)
            ->lockForUpdate()
            ->first();
    }

    private function link(Incident $incident, Signal $signal, string $reason, Carbon $now): void
    {
        IncidentSignal::query()->firstOrCreate(
            ['incident_id' => $incident->id, 'signal_id' => $signal->id],
            ['tenant_id' => $incident->tenant_id, 'store_id' => $incident->store_id, 'association_reason' => $reason, 'linked_at' => $now],
        );
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function activity(Incident $incident, string $kind, array $extra): void
    {
        IncidentActivity::query()->create([
            'tenant_id' => $incident->tenant_id,
            'store_id' => $incident->store_id,
            'incident_id' => $incident->id,
            'kind' => $kind,
            'actor_id' => null,
            'incident_revision' => $incident->revision,
            'sanitized_data' => $extra,
            'created_at' => Carbon::now(),
        ]);
    }
}
