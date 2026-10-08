<?php

namespace App\Http\Controllers\Api\V1\Ingest;

use App\Http\Controllers\Controller;
use App\Models\EventInbox;
use App\Models\Integration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EventsController extends Controller
{
    private const EVENT_TYPES = [
        'order.snapshot',
        'order.deleted',
        'refund.snapshot',
        'payment.snapshot',
        'transaction.observed',
        'integration.heartbeat',
        'integration.capabilities_changed',
        'deployment.observed',
        'funnel.observed',
    ];

    private const AGGREGATE_TYPES = [
        'order',
        'refund',
        'payment',
        'transaction',
        'integration',
        'deployment',
        'session',
    ];

    public function store(Request $request): JsonResponse
    {
        /** @var Integration $integration */
        $integration = $request->attributes->get('integration');
        $requestId = (string) Str::uuid();
        $body = $request->json()->all();

        if (! is_array($body) || ! array_key_exists('events', $body) || ! is_array($body['events'])) {
            return $this->problem('schema_invalid', 'The events batch envelope is invalid.', 422, $requestId);
        }

        if (count($body['events']) < 1 || count($body['events']) > 100) {
            return $this->problem('schema_invalid', 'The events batch size is invalid.', 422, $requestId);
        }

        $results = DB::transaction(function () use ($body, $integration, $requestId): array {
            $results = [];

            foreach (array_values($body['events']) as $index => $event) {
                if (! is_array($event)) {
                    $results[] = $this->recordResult($index, null, null, 'invalid', 'schema_invalid');
                    continue;
                }

                $error = $this->validateEvent($event);
                $eventId = is_string($event['event_id'] ?? null) ? $event['event_id'] : null;

                if ($error !== null) {
                    $results[] = $this->recordResult($index, $eventId, null, 'invalid', $error);
                    continue;
                }

                $payloadHash = $this->payloadHash($event);
                /** @var EventInbox|null $existing */
                $existing = EventInbox::query()
                    ->where('integration_id', $integration->id)
                    ->where('provider_event_id', $event['event_id'])
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    if ($existing->payload_hash === $payloadHash) {
                        $results[] = $this->recordResult($index, $event['event_id'], $existing->id, 'duplicate');
                    } else {
                        $results[] = $this->recordResult($index, $event['event_id'], $existing->id, 'conflict', 'event_id_conflict');
                    }

                    continue;
                }

                $inbox = EventInbox::query()->create([
                    'tenant_id' => $integration->tenant_id,
                    'store_id' => $integration->store_id,
                    'integration_id' => $integration->id,
                    'provider_event_id' => $event['event_id'],
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
                    'status' => EventInbox::STATUS_RECEIVED,
                    'attempt_count' => 0,
                    'next_attempt_at' => now(),
                    'request_id' => $requestId,
                ]);

                $results[] = $this->recordResult($index, $event['event_id'], $inbox->id, 'accepted');
            }

            return $results;
        });

        $status = collect($results)->every(fn (array $result): bool => in_array($result['status'], ['accepted', 'duplicate'], true))
            ? 202
            : 207;

        return response()->json([
            'request_id' => $requestId,
            'results' => $results,
        ], $status);
    }

    private function validateEvent(array $event): ?string
    {
        foreach (['schema_version', 'event_id', 'type', 'aggregate_type', 'aggregate_id', 'occurred_at', 'observed_at', 'is_synthetic', 'data'] as $field) {
            if (! array_key_exists($field, $event)) {
                return 'schema_invalid';
            }
        }

        if ($event['schema_version'] !== '1.0') {
            return 'schema_unsupported';
        }

        if (! is_string($event['event_id']) || ! Str::isUuid($event['event_id'])) {
            return 'schema_invalid';
        }

        if (! is_string($event['type']) || ! in_array($event['type'], self::EVENT_TYPES, true)) {
            return 'schema_invalid';
        }

        if (! is_string($event['aggregate_type']) || ! in_array($event['aggregate_type'], self::AGGREGATE_TYPES, true)) {
            return 'schema_invalid';
        }

        if (! is_string($event['aggregate_id']) || $event['aggregate_id'] === '' || mb_strlen($event['aggregate_id']) > 255) {
            return 'schema_invalid';
        }

        if (array_key_exists('aggregate_revision', $event) && (! is_int($event['aggregate_revision']) || $event['aggregate_revision'] < 0)) {
            return 'schema_invalid';
        }

        if (! is_bool($event['is_synthetic']) || ! is_array($event['data'])) {
            return 'schema_invalid';
        }

        if (! $this->isRfc3339DateTime($event['occurred_at']) || ! $this->isRfc3339DateTime($event['observed_at'])) {
            return 'schema_invalid';
        }

        if (strtotime($event['observed_at']) > time() + 300 || strtotime($event['occurred_at']) > time() + 300) {
            return 'clock_skew';
        }

        return null;
    }

    private function isRfc3339DateTime(mixed $value): bool
    {
        return is_string($value) && strtotime($value) !== false;
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
