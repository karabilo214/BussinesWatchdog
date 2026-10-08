<?php

namespace App\Http\Controllers\Api\V1\Ingest;

use App\Http\Controllers\Controller;
use App\Models\DomainOutbox;
use App\Models\EventInbox;
use App\Models\Integration;
use App\Support\Ingest\EventPayloadValidator;
use App\Support\Ingest\EventValidationResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EventsController extends Controller
{
    private const MAX_BODY_BYTES = 1048576;

    public const ERROR_REQUEST_TOO_LARGE = 'request_too_large';

    public const ERROR_MALFORMED_JSON = 'malformed_json';

    public const ERROR_EVENT_ID_CONFLICT = 'event_id_conflict';

    public const RESULT_ACCEPTED = 'accepted';

    public const RESULT_DUPLICATE = 'duplicate';

    public const RESULT_INVALID = 'invalid';

    public const RESULT_CONFLICT = 'conflict';

    public const RESULT_QUARANTINED = 'quarantined';

    public function __construct(
        private readonly EventPayloadValidator $validator,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        /** @var Integration $integration */
        $integration = $request->attributes->get('integration');
        $requestId = (string) Str::uuid();
        $rawBody = $request->getContent();

        if (strlen($rawBody) > self::MAX_BODY_BYTES) {
            return $this->problem(self::ERROR_REQUEST_TOO_LARGE, 'The events batch exceeds the maximum size.', 413, $requestId);
        }

        try {
            $body = json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->problem(self::ERROR_MALFORMED_JSON, 'The request body is not valid JSON.', 400, $requestId);
        }

        if (! is_array($body) || ! array_key_exists('events', $body) || ! is_array($body['events'])) {
            return $this->problem(EventValidationResult::ERROR_SCHEMA_INVALID, 'The events batch envelope is invalid.', 422, $requestId);
        }

        if (count($body['events']) < 1 || count($body['events']) > 100) {
            return $this->problem(EventValidationResult::ERROR_SCHEMA_INVALID, 'The events batch size is invalid.', 422, $requestId);
        }

        $results = DB::transaction(function () use ($body, $integration, $requestId): array {
            $results = [];

            foreach (array_values($body['events']) as $index => $event) {
                if (! is_array($event)) {
                    $results[] = $this->recordResult($index, null, null, self::RESULT_INVALID, EventValidationResult::ERROR_SCHEMA_INVALID);
                    continue;
                }

                $validation = $this->validator->validate($event);
                $eventId = is_string($event['event_id'] ?? null) ? $event['event_id'] : null;
                $payloadHash = $this->payloadHash($event);

                if (! $validation->valid && ! $validation->quarantinable) {
                    $results[] = $this->recordResult($index, $eventId, null, self::RESULT_INVALID, $validation->errorCode);
                    continue;
                }

                /** @var EventInbox|null $existing */
                $existing = $eventId === null
                    ? null
                    : EventInbox::query()
                        ->where('integration_id', $integration->id)
                        ->where('provider_event_id', $eventId)
                        ->lockForUpdate()
                        ->first();

                if ($existing !== null) {
                    if ($existing->payload_hash === $payloadHash) {
                        $results[] = $this->recordResult($index, $eventId, $existing->id, self::RESULT_DUPLICATE);
                    } else {
                        $results[] = $this->recordResult($index, $eventId, $existing->id, self::RESULT_CONFLICT, self::ERROR_EVENT_ID_CONFLICT);
                    }

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
                    ? $this->recordResult($index, $eventId, $inbox->id, self::RESULT_ACCEPTED)
                    : $this->recordResult($index, $eventId, $inbox->id, self::RESULT_QUARANTINED, $validation->errorCode);
            }

            return $results;
        });

        $status = collect($results)->every(fn (array $result): bool => in_array($result['status'], [self::RESULT_ACCEPTED, self::RESULT_DUPLICATE], true))
            ? 202
            : 207;

        return response()->json([
            'request_id' => $requestId,
            'results' => $results,
        ], $status);
    }

    private function payloadHash(array $event): string
    {
        $canonical = $this->canonicalize($event);

        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
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

    private function recordResult(int $index, ?string $eventId, ?string $inboxId, string $status, ?string $code = null): array
    {
        return array_filter([
            'index' => $index,
            'event_id' => $eventId,
            'inbox_id' => $inboxId,
            'status' => $status,
            'code' => $code,
        ], fn (mixed $value): bool => $value !== null);
    }

    private function problem(string $code, string $message, int $status, string $requestId): JsonResponse
    {
        return response()->json([
            'code' => $code,
            'message' => $message,
            'request_id' => $requestId,
        ], $status);
    }
}
