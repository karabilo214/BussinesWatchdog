<?php

namespace App\Http\Controllers\Api\V1\Ingest;

use App\Http\Controllers\Controller;
use App\Support\Ingest\EventIngestor;
use App\Support\Ingest\EventValidationResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class EventsController extends Controller
{
    private const MAX_BODY_BYTES = 1048576;

    public const ERROR_REQUEST_TOO_LARGE = 'request_too_large';

    public const ERROR_MALFORMED_JSON = 'malformed_json';

    public const ERROR_EVENT_ID_CONFLICT = EventIngestor::ERROR_EVENT_ID_CONFLICT;

    public const ERROR_SOURCE_AUTHORITY_NOT_PERMITTED = EventIngestor::ERROR_SOURCE_AUTHORITY_NOT_PERMITTED;

    public const RESULT_ACCEPTED = EventIngestor::RESULT_ACCEPTED;

    public const RESULT_DUPLICATE = EventIngestor::RESULT_DUPLICATE;

    public const RESULT_INVALID = EventIngestor::RESULT_INVALID;

    public const RESULT_CONFLICT = EventIngestor::RESULT_CONFLICT;

    public const RESULT_QUARANTINED = EventIngestor::RESULT_QUARANTINED;

    public function __construct(
        private readonly EventIngestor $ingestor,
    ) {}

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
            $decoded = json_decode($rawBody, false, flags: JSON_THROW_ON_ERROR);
            $rawEvents = is_object($decoded) ? ($decoded->events ?? null) : null;
        } catch (\JsonException) {
            return $this->problem(self::ERROR_MALFORMED_JSON, 'The request body is not valid JSON.', 400, $requestId);
        }

        if (! is_array($body) || ! array_key_exists('events', $body) || ! is_array($body['events'])) {
            return $this->problem(EventValidationResult::ERROR_SCHEMA_INVALID, 'The events batch envelope is invalid.', 422, $requestId);
        }

        if (count($body['events']) < 1 || count($body['events']) > 100) {
            return $this->problem(EventValidationResult::ERROR_SCHEMA_INVALID, 'The events batch size is invalid.', 422, $requestId);
        }

        $results = $this->ingestor->ingest($integration, array_values($body['events']), is_array($rawEvents) ? $rawEvents : null, $requestId);

        $status = collect($results)->every(fn (array $result): bool => in_array($result['status'], [self::RESULT_ACCEPTED, self::RESULT_DUPLICATE], true))
            ? 202
            : 207;

        return response()->json([
            'request_id' => $requestId,
            'results' => $results,
        ], $status);
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
