<?php

namespace App\Http\Dto\Incidents;

use App\Models\Signal;
use Illuminate\Support\Collection;

class SignalDto
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Signal $signal): array
    {
        return [
            'id' => $signal->id,
            'signal_type' => $signal->signal_type,
            'family' => $signal->family,
            'component' => $signal->component,
            'severity' => $signal->severity,
            'confidence' => $signal->confidence,
            'rule_version' => $signal->rule_version,
            'config_version' => $signal->config_version,
            'currency' => $signal->currency,
            'finding_id' => $signal->finding_id,
            'evidence' => $signal->evidence ?? [],
            'detected_at' => $signal->detected_at?->toJSON(),
        ];
    }

    /**
     * @param  Collection<int, Signal>  $signals
     * @return list<array<string, mixed>>
     */
    public function collection(Collection $signals): array
    {
        return $signals
            ->map(fn (Signal $signal): array => $this->toArray($signal))
            ->all();
    }
}
