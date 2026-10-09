<?php

namespace App\Http\Dto\Incidents;

use App\Models\Incident;
use App\Models\IncidentSignal;
use App\Models\ReconciliationFinding;
use App\Models\Signal;
use Illuminate\Support\Collection;

class IncidentDto
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Incident $incident, ?int $currencyExponent = null): array
    {
        $currencyExponent ??= $this->currencyExponents(collect([$incident]))[$incident->id] ?? null;

        return [
            'id' => $incident->id,
            'store_id' => $incident->store_id,
            'family' => $incident->family,
            'component' => $incident->component,
            'state' => $incident->state,
            'severity' => $incident->severity,
            'title_code' => $incident->title_code,
            'currency' => $incident->currency,
            'currency_exponent' => $currencyExponent,
            'verified_discrepancy_minor' => $incident->verified_discrepancy_minor === null
                ? null
                : (string) $incident->verified_discrepancy_minor,
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
    public function collection(Collection $incidents): array
    {
        $exponents = $this->currencyExponents($incidents);

        return $incidents
            ->map(fn (Incident $incident): array => $this->toArray($incident, $exponents[$incident->id] ?? null))
            ->values()
            ->all();
    }

    /**
     * Exponent of the money amounts, taken from the latest reconciliation finding linked to each incident.
     *
     * @param  Collection<int, Incident>  $incidents
     * @return array<string, int>
     */
    private function currencyExponents(Collection $incidents): array
    {
        $ids = $incidents
            ->filter(fn (Incident $incident): bool => $incident->currency !== null)
            ->pluck('id')
            ->all();

        if ($ids === []) {
            return [];
        }

        $rows = IncidentSignal::query()
            ->join((new Signal)->getTable().' as s', 's.id', '=', 'incident_signals.signal_id')
            ->join((new ReconciliationFinding)->getTable().' as f', 'f.id', '=', 's.finding_id')
            ->whereColumn('s.tenant_id', 'incident_signals.tenant_id')
            ->whereColumn('f.tenant_id', 'incident_signals.tenant_id')
            ->whereIn('incident_signals.incident_id', $ids)
            ->whereNotNull('f.currency_exponent')
            ->orderBy('s.detected_at')
            ->get(['incident_signals.incident_id', 'f.currency_exponent']);

        $exponents = [];

        foreach ($rows as $row) {
            $exponents[$row->incident_id] = (int) $row->currency_exponent;
        }

        return $exponents;
    }
}
