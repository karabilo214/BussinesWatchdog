<?php

namespace App\Support\Providers\Stripe;

use App\Models\Integration;
use App\Support\Providers\ProviderEventEmitter;

/** Stripe objects → normalized drafts → change-detecting emission (see ProviderEventEmitter). */
class StripeObjectIngestor
{
    public function __construct(
        private readonly StripeEventMapper $mapper,
        private readonly ProviderEventEmitter $emitter,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $objects
     * @return array{emitted: int, unchanged: int, skipped: array<string, int>}
     */
    public function ingest(Integration $integration, array $objects): array
    {
        $summary = ['emitted' => 0, 'unchanged' => 0, 'skipped' => []];

        foreach ($objects as $object) {
            $mapped = $this->mapper->map($integration, $object);

            if ($mapped['skipped'] !== null) {
                $summary['skipped'][$mapped['skipped']] = ($summary['skipped'][$mapped['skipped']] ?? 0) + 1;

                continue;
            }

            $emitted = $this->emitter->emit($integration, $mapped['drafts']);
            $summary['emitted'] += $emitted['emitted'];
            $summary['unchanged'] += $emitted['unchanged'];
        }

        return $summary;
    }
}
