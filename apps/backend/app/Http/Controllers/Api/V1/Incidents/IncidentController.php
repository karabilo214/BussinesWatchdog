<?php

namespace App\Http\Controllers\Api\V1\Incidents;

use App\Exceptions\Incidents\IncidentActionRejected;
use App\Http\Controllers\Controller;
use App\Http\Dto\Incidents\IncidentActivityDto;
use App\Http\Dto\Incidents\IncidentDto;
use App\Http\Dto\Incidents\SignalDto;
use App\Http\Dto\Incidents\SuppressionDto;
use App\Http\Requests\Incidents\CommentIncidentRequest;
use App\Http\Requests\Incidents\ListIncidentsRequest;
use App\Http\Requests\Incidents\ResolveIncidentRequest;
use App\Http\Requests\Incidents\SnoozeIncidentRequest;
use App\Models\Incident;
use App\Models\IncidentSignal;
use App\Models\Signal;
use App\Support\Api\UuidCursor;
use App\Support\Incidents\IncidentLifecycleService;
use App\Support\Tenancy\TenantContext;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IncidentController extends Controller
{
    public function __construct(
        private readonly IncidentDto $incidentDto,
        private readonly IncidentActivityDto $activityDto,
        private readonly SignalDto $signalDto,
        private readonly SuppressionDto $suppressionDto,
        private readonly IncidentLifecycleService $lifecycle,
    ) {}

    public function index(ListIncidentsRequest $request, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->requireTenantId('list incidents');
        $validated = $request->validated();
        $limit = $validated['limit'] ?? 50;

        $query = Incident::query()->where('tenant_id', $tenantId);

        if (isset($validated['store_id'])) {
            $query->where('store_id', $validated['store_id']);
        }

        if (isset($validated['state'])) {
            $query->where('state', $validated['state']);
        }

        if (isset($validated['severity'])) {
            $query->where('severity', $validated['severity']);
        }

        if (isset($validated['family'])) {
            $query->where('family', $validated['family']);
        }

        if (isset($validated['currency'])) {
            $query->where('currency', $validated['currency']);
        }

        if (isset($validated['cursor'])) {
            $cursorId = UuidCursor::decode($validated['cursor']);
            abort_if($cursorId === null, 400, 'Invalid cursor.');

            $query->where('id', '>', $cursorId);
        }

        $items = $query->orderBy('id')->limit($limit + 1)->get();
        $hasMore = $items->count() > $limit;
        $items = $items->take($limit);

        $nextCursor = ($hasMore && $items->isNotEmpty())
            ? UuidCursor::encode($items->last()->id)
            : null;

        return response()->json([
            'data' => $this->incidentDto->collection($items),
            'next_cursor' => $nextCursor,
        ]);
    }

    public function show(Incident $incident, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->requireTenantId('read incident');

        abort_unless($incident->tenant_id === $tenantId, 404);

        $signalIds = IncidentSignal::query()
            ->where('incident_id', $incident->id)
            ->pluck('signal_id');
        $signals = Signal::query()->whereIn('id', $signalIds)->orderBy('detected_at')->get();

        return response()->json(array_merge($this->incidentDto->toArray($incident), [
            'signals' => $this->signalDto->collection($signals),
            'activity' => $this->activityDto->collection($incident->activity),
        ]));
    }

    public function acknowledge(Request $request, Incident $incident, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->requireTenantId('acknowledge incident');

        abort_unless($incident->tenant_id === $tenantId, 404);

        try {
            $acknowledged = $this->lifecycle->acknowledge($incident, $request->user()?->id);
        } catch (IncidentActionRejected $exception) {
            return $this->rejectedResponse($exception);
        }

        return response()->json($this->incidentDto->toArray($acknowledged));
    }

    public function resolve(ResolveIncidentRequest $request, Incident $incident, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->requireTenantId('resolve incident');

        abort_unless($incident->tenant_id === $tenantId, 404);

        try {
            $resolved = $this->lifecycle->resolve($incident, $request->validated()['reason'], $request->user()?->id);
        } catch (IncidentActionRejected $exception) {
            return $this->rejectedResponse($exception);
        }

        return response()->json($this->incidentDto->toArray($resolved));
    }

    public function comment(CommentIncidentRequest $request, Incident $incident, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->requireTenantId('comment on incident');

        abort_unless($incident->tenant_id === $tenantId, 404);

        try {
            $activity = $this->lifecycle->comment($incident, $request->validated()['text'], $request->user()?->id);
        } catch (IncidentActionRejected $exception) {
            return $this->rejectedResponse($exception);
        }

        return response()->json($this->activityDto->toArray($activity), 201);
    }

    public function snooze(SnoozeIncidentRequest $request, Incident $incident, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->requireTenantId('snooze incident');

        abort_unless($incident->tenant_id === $tenantId, 404);

        $validated = $request->validated();

        try {
            $suppression = $this->lifecycle->snooze(
                $incident,
                new DateTimeImmutable($validated['until']),
                $validated['reason'],
                $request->user()?->id,
            );
        } catch (IncidentActionRejected $exception) {
            return $this->rejectedResponse($exception);
        }

        return response()->json($this->suppressionDto->toArray($suppression), 201);
    }

    private function rejectedResponse(IncidentActionRejected $exception): JsonResponse
    {
        return response()->json([
            'code' => $exception->reasonCode,
            'message' => $exception->getMessage(),
        ], $exception->httpStatus());
    }
}
