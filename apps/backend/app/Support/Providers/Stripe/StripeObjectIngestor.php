<?php

namespace App\Support\Providers\Stripe;

use App\Models\Integration;
use App\Support\Ingest\EventIngestor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

/**
 * Emits an event only when the normalized content of an object changed since it was last seen; the event id is
 * derived from the previous and the new content, so a webhook and a poll that race produce the same event, and a
 * return to an earlier state is still a new event.
 */
class StripeObjectIngestor
{
    private const NAMESPACE = 'f3c3a9b4-6f0a-4d8e-9f6e-2d9b1f4a7c11';

    public function __construct(
        private readonly StripeEventMapper $mapper,
        private readonly EventIngestor $ingestor,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $objects
     * @return array{emitted: int, unchanged: int, skipped: array<string, int>}
     */
    public function ingest(Integration $integration, array $objects): array
    {
        $summary = ['emitted' => 0, 'unchanged' => 0, 'skipped' => []];
        $now = Carbon::now();
        $requestId = (string) Str::uuid();

        foreach ($objects as $object) {
            $mapped = $this->mapper->map($integration, $object);

            if ($mapped['skipped'] !== null) {
                $summary['skipped'][$mapped['skipped']] = ($summary['skipped'][$mapped['skipped']] ?? 0) + 1;

                continue;
            }

            foreach ($mapped['drafts'] as $draft) {
                $objectType = $draft['aggregate_type'];
                $contentHash = hash('sha256', json_encode([$draft['type'], $draft['aggregate_id'], $draft['data']], JSON_THROW_ON_ERROR));

                $changed = DB::transaction(function () use ($integration, $draft, $objectType, $contentHash, $now, $requestId): bool {
                    $known = DB::table('provider_object_states')
                        ->where('integration_id', $integration->id)
                        ->where('object_type', $objectType)
                        ->where('object_id', $draft['aggregate_id'])
                        ->lockForUpdate()
                        ->value('content_hash');

                    if ($known === $contentHash) {
                        return false;
                    }

                    $data = $draft['data'];

                    if ($draft['type'] === 'payment.snapshot') {
                        $data['source_updated_at'] = $now->toJSON();
                    }

                    $this->ingestor->ingest($integration, [[
                        'schema_version' => '1.0',
                        'event_id' => Uuid::uuid5(self::NAMESPACE, $integration->id.'|'.($known ?? 'new').'|'.$contentHash)->toString(),
                        'type' => $draft['type'],
                        'aggregate_type' => $draft['aggregate_type'],
                        'aggregate_id' => $draft['aggregate_id'],
                        'occurred_at' => Carbon::createFromTimestamp($draft['created'])->toJSON(),
                        'observed_at' => $now->toJSON(),
                        'is_synthetic' => false,
                        'data' => $data,
                    ]], null, $requestId);

                    DB::table('provider_object_states')->upsert([[
                        'tenant_id' => $integration->tenant_id,
                        'store_id' => $integration->store_id,
                        'integration_id' => $integration->id,
                        'object_type' => $objectType,
                        'object_id' => $draft['aggregate_id'],
                        'content_hash' => $contentHash,
                        'observed_at' => $now,
                    ]], ['integration_id', 'object_type', 'object_id'], ['content_hash', 'observed_at']);

                    return true;
                });

                $summary[$changed ? 'emitted' : 'unchanged']++;
            }
        }

        return $summary;
    }
}
