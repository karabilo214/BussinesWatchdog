<?php

namespace App\Support\Incidents;

use App\Exceptions\Incidents\IncidentActionRejected;
use App\Models\Incident;
use App\Models\IncidentActivity;
use App\Models\Suppression;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class IncidentLifecycleService
{
    public function acknowledge(Incident $incident, ?string $actorId): Incident
    {
        return DB::transaction(function () use ($incident, $actorId): Incident {
            /** @var Incident $locked */
            $locked = Incident::query()->whereKey($incident->id)->lockForUpdate()->firstOrFail();

            if ($locked->state === Incident::STATE_RESOLVED) {
                throw new IncidentActionRejected('incident_resolved_cannot_acknowledge');
            }

            if ($locked->state === Incident::STATE_ACKNOWLEDGED) {
                return $locked;
            }

            $now = Carbon::now();
            $locked->forceFill([
                'state' => Incident::STATE_ACKNOWLEDGED,
                'acknowledged_by' => $actorId,
                'acknowledged_at' => $now,
                'revision' => $locked->revision + 1,
                'updated_at' => $now,
            ])->save();

            $this->writeActivity($locked, IncidentActivity::KIND_ACKNOWLEDGED, $actorId, []);

            return $locked->refresh();
        });
    }

    public function resolve(Incident $incident, string $reason, ?string $actorId): Incident
    {
        return DB::transaction(function () use ($incident, $reason, $actorId): Incident {
            $this->assertNonEmpty($reason, 'incident_reason_required');

            /** @var Incident $locked */
            $locked = Incident::query()->whereKey($incident->id)->lockForUpdate()->firstOrFail();

            if ($locked->state === Incident::STATE_RESOLVED) {
                throw new IncidentActionRejected('incident_already_resolved');
            }

            $now = Carbon::now();
            $locked->forceFill([
                'state' => Incident::STATE_RESOLVED,
                'resolved_at' => $now,
                'resolution_reason' => $reason,
                'revision' => $locked->revision + 1,
                'updated_at' => $now,
            ])->save();

            $this->writeActivity($locked, IncidentActivity::KIND_RESOLVED, $actorId, ['reason' => $reason]);

            return $locked->refresh();
        });
    }

    public function comment(Incident $incident, string $text, ?string $actorId): IncidentActivity
    {
        $this->assertNonEmpty($text, 'incident_comment_required');

        /** @var Incident $current */
        $current = Incident::query()->whereKey($incident->id)->firstOrFail();

        return IncidentActivity::query()->create([
            'tenant_id' => $current->tenant_id,
            'store_id' => $current->store_id,
            'incident_id' => $current->id,
            'kind' => IncidentActivity::KIND_COMMENT,
            'actor_id' => $actorId,
            'incident_revision' => $current->revision,
            'sanitized_data' => ['text' => $text],
            'created_at' => Carbon::now(),
        ]);
    }

    public function snooze(Incident $incident, DateTimeInterface $until, string $reason, ?string $actorId): Suppression
    {
        return DB::transaction(function () use ($incident, $until, $reason, $actorId): Suppression {
            $this->assertNonEmpty($reason, 'incident_reason_required');

            $now = Carbon::now();
            $untilCarbon = Carbon::instance($until);

            if ($untilCarbon->lessThanOrEqualTo($now)) {
                throw new IncidentActionRejected('suppression_window_invalid');
            }

            if ($untilCarbon->greaterThan($now->copy()->addDays(Suppression::MAX_WINDOW_DAYS))) {
                throw new IncidentActionRejected('suppression_window_too_long');
            }

            /** @var Incident $locked */
            $locked = Incident::query()->whereKey($incident->id)->lockForUpdate()->firstOrFail();

            $suppression = Suppression::query()->create([
                'tenant_id' => $locked->tenant_id,
                'store_id' => $locked->store_id,
                'incident_id' => $locked->id,
                'scope' => ['incident_id' => $locked->id],
                'reason' => $reason,
                'created_by' => $actorId,
                'starts_at' => $now,
                'ends_at' => $untilCarbon,
                'created_at' => $now,
            ]);

            $this->writeActivity($locked, IncidentActivity::KIND_SUPPRESSED, $actorId, [
                'until' => $untilCarbon->toJSON(),
                'reason' => $reason,
            ]);

            return $suppression;
        });
    }

    public function revokeSuppression(Suppression $suppression): Suppression
    {
        return DB::transaction(function () use ($suppression): Suppression {
            /** @var Suppression $locked */
            $locked = Suppression::query()->whereKey($suppression->id)->lockForUpdate()->firstOrFail();

            if ($locked->revoked_at !== null) {
                throw new IncidentActionRejected('suppression_already_revoked');
            }

            $locked->forceFill(['revoked_at' => Carbon::now()])->save();

            return $locked->refresh();
        });
    }

    private function assertNonEmpty(string $value, string $reasonCode): void
    {
        if (trim($value) === '') {
            throw new IncidentActionRejected($reasonCode);
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function writeActivity(Incident $incident, string $kind, ?string $actorId, array $extra): void
    {
        IncidentActivity::query()->create([
            'tenant_id' => $incident->tenant_id,
            'store_id' => $incident->store_id,
            'incident_id' => $incident->id,
            'kind' => $kind,
            'actor_id' => $actorId,
            'incident_revision' => $incident->revision,
            'sanitized_data' => $extra,
            'created_at' => Carbon::now(),
        ]);
    }
}
