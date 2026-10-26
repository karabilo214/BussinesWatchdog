<?php

namespace App\Support\Projections;

use App\Models\EventInbox;
use App\Models\Payment;
use App\Models\ProviderDispute;
use App\Support\Disputes\DisputeIncidents;
use App\Support\Ingest\EventProjectionResult;
use Illuminate\Support\Carbon;

class DisputeSnapshotProjector
{
    public const ERROR_INVALID = 'dispute_snapshot_invalid';

    public function __construct(private readonly DisputeIncidents $incidents) {}

    public function project(EventInbox $event): EventProjectionResult
    {
        if ($event->event_type !== EventInbox::EVENT_DISPUTE_SNAPSHOT || $event->aggregate_type !== EventInbox::AGGREGATE_DISPUTE) {
            return EventProjectionResult::ok();
        }

        $data = is_array($event->payload['data'] ?? null) ? $event->payload['data'] : null;
        $revision = $event->aggregate_revision;

        if ($data === null || $revision === null || ! in_array($data['status'] ?? null, ProviderDispute::STATUSES, true) || ! is_string($data['payment_external_id'] ?? null)) {
            return EventProjectionResult::failed(self::ERROR_INVALID);
        }

        /** @var ProviderDispute|null $dispute */
        $dispute = ProviderDispute::query()
            ->where('integration_id', $event->integration_id)
            ->where('external_id', $event->aggregate_external_id)
            ->lockForUpdate()
            ->first();

        if ($dispute !== null && $dispute->source_revision >= $revision) {
            return EventProjectionResult::ok();
        }

        $now = Carbon::now();
        $open = in_array($data['status'], ProviderDispute::OPEN_STATUSES, true);
        $paymentId = Payment::query()
            ->where('tenant_id', $event->tenant_id)
            ->where('store_id', $event->store_id)
            ->where('integration_id', $event->integration_id)
            ->where('external_id', $data['payment_external_id'])
            ->value('id');

        $attributes = [
            'tenant_id' => $event->tenant_id,
            'store_id' => $event->store_id,
            'integration_id' => $event->integration_id,
            'payment_id' => $paymentId ?? $dispute?->payment_id,
            'external_id' => $event->aggregate_external_id,
            'payment_external_id' => $data['payment_external_id'],
            'charge_ref' => ProjectionValueNormalizer::nullableString($data['charge_ref'] ?? null),
            'mode' => $data['mode'],
            'currency' => $data['currency'],
            'currency_exponent' => $data['currency_exponent'],
            'amount_minor' => $data['amount_minor'],
            'status' => $data['status'],
            'reason' => ProjectionValueNormalizer::nullableString($data['reason'] ?? null),
            'evidence_due_by' => $data['evidence_due_by'] ?? null,
            'opened_at' => $data['opened_at'],
            'closed_at' => $open ? null : ($dispute?->closed_at ?? $now),
            'source_revision' => $revision,
            'source_event_id' => $event->id,
            'updated_at' => $now,
        ];

        if ($dispute === null) {
            $dispute = ProviderDispute::query()->create($attributes + [
                'incident_fingerprint' => DisputeIncidents::fingerprintFor((string) $event->integration_id, (string) $event->aggregate_external_id),
                'created_at' => $now,
            ]);
        } else {
            $dispute->forceFill($attributes)->save();
        }

        $this->incidents->sync($dispute);

        return EventProjectionResult::ok();
    }
}
