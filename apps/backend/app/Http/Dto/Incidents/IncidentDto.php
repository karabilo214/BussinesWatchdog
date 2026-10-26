<?php

namespace App\Http\Dto\Incidents;

use App\Http\Dto\Disputes\DisputeDto;
use App\Models\Incident;
use App\Models\IncidentSignal;
use App\Models\Order;
use App\Models\ReconciliationFinding;
use App\Models\Signal;
use App\Support\Disputes\DisputeIncidents;
use App\Support\Disputes\DisputeOrders;
use Illuminate\Support\Collection;

class IncidentDto
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Incident $incident, ?array $facts = null, bool $showMoneyAmounts = true): array
    {
        $facts ??= $this->findingFacts(collect([$incident]))[$incident->id] ?? [];
        $dispute = $incident->family === DisputeIncidents::FAMILY
            ? app(DisputeOrders::class)->forIncident($incident->tenant_id, $incident->store_id, $incident->fingerprint)
            : null;

        if ($dispute !== null) {
            $order = app(DisputeOrders::class)->order($dispute);
            $facts += array_filter(['order_id' => $order?->id, 'order_display_number' => $order?->display_number], fn (mixed $value): bool => $value !== null);
        }

        return [
            'id' => $incident->id,
            'store_id' => $incident->store_id,
            'family' => $incident->family,
            'component' => $incident->component,
            'state' => $incident->state,
            'severity' => $incident->severity,
            'title_code' => $incident->title_code,
            'currency' => $incident->currency,
            'mode' => $incident->mode,
            'currency_exponent' => $facts['currency_exponent'] ?? null,
            'order_id' => $facts['order_id'] ?? null,
            'order_display_number' => $facts['order_display_number'] ?? null,
            'verified_discrepancy_minor' => ! $showMoneyAmounts || $incident->verified_discrepancy_minor === null
                ? null
                : (string) $incident->verified_discrepancy_minor,
            'money_amounts_hidden' => ! $showMoneyAmounts && in_array($incident->family, ['money', DisputeIncidents::FAMILY], true),
            'dispute' => $dispute === null ? null : app(DisputeDto::class)->toArray($dispute, $showMoneyAmounts),
            'first_seen_at' => $incident->first_seen_at?->toJSON(),
            'last_seen_at' => $incident->last_seen_at?->toJSON(),
            'last_good_at' => $incident->last_good_at?->toJSON(),
            'first_bad_at' => $incident->first_bad_at?->toJSON(),
            'acknowledged_by' => $incident->acknowledged_by,
            'acknowledged_at' => $incident->acknowledged_at?->toJSON(),
            'resolved_at' => $incident->resolved_at?->toJSON(),
            'resolution_reason' => $incident->resolution_reason,
            'revision' => $incident->revision,
            'created_at' => $incident->created_at?->toJSON(),
            'updated_at' => $incident->updated_at?->toJSON(),
        ];
    }

    /**
     * @param  Collection<int, Incident>  $incidents
     * @return list<array<string, mixed>>
     */
    public function collection(Collection $incidents, bool $showMoneyAmounts = true): array
    {
        $facts = $this->findingFacts($incidents);

        return $incidents
            ->map(fn (Incident $incident): array => $this->toArray($incident, $facts[$incident->id] ?? [], $showMoneyAmounts))
            ->values()
            ->all();
    }

    /**
     * The currency exponent of the money amounts and the store order of a money incident, taken from the latest
     * reconciliation finding linked to each incident.
     *
     * @param  Collection<int, Incident>  $incidents
     * @return array<string, array{currency_exponent?: int, order_id?: string, order_display_number?: ?string}>
     */
    private function findingFacts(Collection $incidents): array
    {
        $ids = $incidents
            ->filter(fn (Incident $incident): bool => $incident->family === 'money')
            ->pluck('id')
            ->all();

        if ($ids === []) {
            return [];
        }

        $rows = IncidentSignal::query()
            ->join((new Signal)->getTable().' as s', 's.id', '=', 'incident_signals.signal_id')
            ->join((new ReconciliationFinding)->getTable().' as f', 'f.id', '=', 's.finding_id')
            ->leftJoin((new Order)->getTable().' as o', function ($join): void {
                $join->on('o.id', '=', 'f.order_id')->whereColumn('o.tenant_id', 'f.tenant_id');
            })
            ->whereColumn('s.tenant_id', 'incident_signals.tenant_id')
            ->whereColumn('f.tenant_id', 'incident_signals.tenant_id')
            ->whereIn('incident_signals.incident_id', $ids)
            ->orderBy('s.detected_at')
            ->get(['incident_signals.incident_id', 'f.currency_exponent', 'f.order_id', 'o.display_number']);

        $facts = [];

        foreach ($rows as $row) {
            if ($row->currency_exponent !== null) {
                $facts[$row->incident_id]['currency_exponent'] = (int) $row->currency_exponent;
            }

            if ($row->order_id !== null) {
                $facts[$row->incident_id]['order_id'] = $row->order_id;
                $facts[$row->incident_id]['order_display_number'] = $row->display_number;
            }
        }

        return $facts;
    }
}
