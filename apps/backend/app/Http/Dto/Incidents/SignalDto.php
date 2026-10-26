<?php

namespace App\Http\Dto\Incidents;

use App\Models\Order;
use App\Models\Signal;
use Illuminate\Support\Collection;

class SignalDto
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Signal $signal): array
    {
        $evidence = $signal->evidence ?? [];
        if ($signal->signal_type === Signal::TYPE_PAYMENT_ATTEMPTS && is_string($evidence['payment_method'] ?? null)) {
            $order = Order::query()->where('tenant_id', $signal->tenant_id)->where('store_id', $signal->store_id)
                ->where('gateway', $evidence['payment_method'])->whereNull('deleted_at')->whereNotNull('metadata->gateway_name')
                ->orderByDesc('source_updated_at')->first(['metadata']);
            $evidence['payment_method_name'] = $order?->metadata['gateway_name'] ?? null;
        }

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
            'evidence' => $evidence,
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
