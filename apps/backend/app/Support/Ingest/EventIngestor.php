<?php

namespace App\Support\Ingest;

use App\Models\DomainOutbox;
use App\Models\EventInbox;
use App\Models\Integration;
use Illuminate\Support\Facades\DB;

/**
 * Durable intake of normalized events for one integration: validation, authority check, idempotency by event id,
 * inbox row and outbox notification in one transaction. Used by the plugin ingress and by server-side provider adapters.
 */
class EventIngestor
{
    public const ERROR_EVENT_ID_CONFLICT = 'event_id_conflict';

    public const ERROR_SOURCE_AUTHORITY_NOT_PERMITTED = 'source_authority_not_permitted';

    public const RESULT_ACCEPTED = 'accepted';

    public const RESULT_DUPLICATE = 'duplicate';

    public const RESULT_INVALID = 'invalid';

    public const RESULT_CONFLICT = 'conflict';

    public const RESULT_QUARANTINED = 'quarantined';

    public function __construct(
        private readonly EventPayloadValidator $validator,
    ) {}

    /**
     * @param  list<mixed>  $events  decoded as arrays
     * @param  list<mixed>|null  $rawEvents  the same events decoded as objects (keeps JSON object/array distinction for schema validation)
     * @return list<array<string, mixed>>
     */
    public function ingest(Integration $integration, array $events, ?array $rawEvents, string $requestId): array
    {
        $rawEvents ??= json_decode(json_encode($events, JSON_THROW_ON_ERROR), false, flags: JSON_THROW_ON_ERROR);

        return DB::transaction(function () use ($integration, $events, $rawEvents, $requestId): array {
            $results = [];

            foreach ($events as $index => $event) {
                if (! is_array($event)) {
                    $results[] = $this->result($index, null, null, self::RESULT_INVALID, EventValidationResult::ERROR_SCHEMA_INVALID);

                    continue;
                }

                $validation = $this->validator->validate($event, $rawEvents[$index] ?? null);
                $eventId = is_string($event['event_id'] ?? null) ? $event['event_id'] : null;
                $payloadHash = $this->payloadHash($event);

                if (! $validation->valid && ! $validation->quarantinable) {
                    $results[] = $this->result($index, $eventId, null, self::RESULT_INVALID, $validation->errorCode);

                    continue;
                }

                if (! $this->authorityPermitted($event, $integration)) {
                    $results[] = $this->result($index, $eventId, null, self::RESULT_INVALID, self::ERROR_SOURCE_AUTHORITY_NOT_PERMITTED);

                    continue;
                }

                $existing = $eventId === null
                    ? null
                    : EventInbox::query()
                        ->where('integration_id', $integration->id)
                        ->where('provider_event_id', $eventId)
                        ->lockForUpdate()
                        ->first();

                if ($existing !== null) {
                    $results[] = $existing->payload_hash === $payloadHash
                        ? $this->result($index, $eventId, $existing->id, self::RESULT_DUPLICATE)
                        : $this->result($index, $eventId, $existing->id, self::RESULT_CONFLICT, self::ERROR_EVENT_ID_CONFLICT);

                    continue;
                }

                $inbox = EventInbox::query()->create([
                    'tenant_id' => $integration->tenant_id,
                    'store_id' => $integration->store_id,
                    'integration_id' => $integration->id,
                    'provider_event_id' => $eventId,
                    'schema_version' => $event['schema_version'],
                    'event_type' => $event['type'],
                    'aggregate_type' => $event['aggregate_type'],
                    'aggregate_external_id' => $event['aggregate_id'],
                    'aggregate_revision' => $event['aggregate_revision'] ?? null,
                    'occurred_at' => $event['occurred_at'],
                    'observed_at' => $event['observed_at'],
                    'received_at' => now(),
                    'is_synthetic' => $event['is_synthetic'],
                    'payload' => $event,
                    'payload_hash' => $payloadHash,
                    'canonicalization_version' => 1,
                    'status' => $validation->valid ? EventInbox::STATUS_RECEIVED : EventInbox::STATUS_QUARANTINED,
                    'attempt_count' => 0,
                    'next_attempt_at' => now(),
                    'error_code' => $validation->errorCode,
                    'request_id' => $requestId,
                ]);

                if ($validation->valid) {
                    DomainOutbox::query()->create([
                        'tenant_id' => $integration->tenant_id,
                        'topic' => DomainOutbox::TOPIC_EVENT_INBOX_RECEIVED,
                        'dedupe_key' => $inbox->id,
                        'payload' => [
                            'event_inbox_id' => $inbox->id,
                            'integration_id' => $integration->id,
                            'store_id' => $integration->store_id,
                            'event_type' => $inbox->event_type,
                        ],
                        'status' => DomainOutbox::STATUS_PENDING,
                        'attempts' => 0,
                        'next_attempt_at' => now(),
                        'created_at' => now(),
                    ]);
                }

                $results[] = $validation->valid
                    ? $this->result($index, $eventId, $inbox->id, self::RESULT_ACCEPTED)
                    : $this->result($index, $eventId, $inbox->id, self::RESULT_QUARANTINED, $validation->errorCode);
            }

            return $results;
        });
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function authorityPermitted(array $event, Integration $integration): bool
    {
        $authority = is_array($event['data'] ?? null) ? ($event['data']['source_authority'] ?? null) : null;

        return $authority !== Integration::SOURCE_INDEPENDENT_PROVIDER
            || $integration->source_authority === Integration::SOURCE_INDEPENDENT_PROVIDER;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function payloadHash(array $event): string
    {
        return hash('sha256', json_encode($this->canonicalize($event), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value);

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function result(int $index, ?string $eventId, ?string $inboxId, string $status, ?string $code = null): array
    {
        return array_filter([
            'index' => $index,
            'event_id' => $eventId,
            'inbox_id' => $inboxId,
            'status' => $status,
            'code' => $code,
        ], fn (mixed $value): bool => $value !== null);
    }
}
